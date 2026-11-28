<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity\Provider;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ulaxys\Identity\Models\User;
use Ulaxys\Identity\Services\Identity\AuditLogger;

final class ProvisionProviderIdentity
{
    public function __construct(
        private readonly KeycloakAdministration $provider,
        private readonly AuditLogger $audit,
    ) {}

    public function stage(string $requestId, ?array $profile = null, ?string $email = null, ?string $actorId = null): string
    {
        if (! Str::isUlid($requestId)) {
            throw new ProviderFailure('A provisioning request ULID is required.');
        }
        $issuer = $this->provider->issuer();
        $requestId = strtoupper($requestId);
        $email = $email === null ? null : strtolower($email);

        $mapping = DB::transaction(function () use ($requestId, $issuer, $profile, $email, $actorId): object {
            DB::table('identity_provider_mappings')->insertOrIgnore([
                'request_id' => $requestId, 'issuer' => $issuer, 'state' => 'provisioning',
                'contact_email' => $email, 'requested_by' => $actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = $this->mappings($requestId)->lockForUpdate()->first();
            if ($row->issuer !== $issuer) {
                throw new ProviderFailure('The request belongs to a different trusted issuer.');
            }
            if (($actorId !== null && $row->requested_by !== $actorId) || ($email !== null && $row->contact_email !== $email)) {
                throw new ProvisioningConflict('The provisioning request is already assigned.');
            }
            if ($row->user_id === null) {
                $user = User::query()->create(['status' => User::STATUS_INVITED, 'type' => User::TYPE_HUMAN, 'created_by' => $actorId]);
                if ($profile !== null) {
                    $user->profile()->create($profile);
                }
                $row->user_id = $user->getKey();
                $this->mappings($requestId)->update(['user_id' => $row->user_id]);
                $this->audit->audit('identity.provider.provision.request', $actorId, $actorId === null ? 'operator' : 'user', 'user', $row->user_id, 'success', correlationId: $requestId);
            }

            return $row;
        });
        if ($mapping->state === 'mapping_conflict') {
            throw new ProviderFailure('The provider subject is already mapped. Operator reconciliation is required.');
        }
        if ($mapping->subject !== null) {
            return $mapping->user_id;
        }

        try {
            $subject = $this->provider->ensureDisabledAccount($mapping->user_id, $mapping->contact_email);
        } catch (ProviderFailure $failure) {
            DB::transaction(function () use ($requestId, $mapping): void {
                if ($this->mappings($requestId)->whereNull('subject')->where('state', '!=', 'retry_required')
                    ->update(['state' => 'retry_required', 'updated_at' => now()]) === 1) {
                    $this->audit->audit('identity.provider.provision.retry', null, 'system', 'user', $mapping->user_id, 'failure', correlationId: $requestId);
                }
            });

            throw $failure;
        }

        try {
            DB::transaction(function () use ($requestId, $mapping, $subject): void {
                $current = $this->mappings($requestId)->lockForUpdate()->first();
                if ($current->subject === $subject) {
                    return;
                }
                if ($current->subject !== null) {
                    throw new ProviderFailure('The provider subject conflicts with the stored mapping.');
                }
                $this->mappings($requestId)->update([
                    'subject' => $subject, 'state' => 'staged_disabled', 'updated_at' => now(),
                ]);
                $this->audit->audit('identity.provider.provision.staged', null, 'system', 'user', $mapping->user_id, 'success', correlationId: $requestId);
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23505') {
                throw $exception;
            }
            DB::transaction(function () use ($requestId, $mapping): void {
                if ($this->mappings($requestId)->whereNull('subject')->where('state', '!=', 'mapping_conflict')
                    ->update(['state' => 'mapping_conflict', 'updated_at' => now()]) === 1) {
                    $this->audit->audit('identity.provider.provision.conflict', null, 'system', 'user', $mapping->user_id, 'failure', correlationId: $requestId);
                }
            });

            throw new ProviderFailure('The provider subject is already mapped. Operator reconciliation is required.');
        }

        return $mapping->user_id;
    }

    private function mappings(string $requestId): Builder
    {
        return DB::table('identity_provider_mappings')->where('request_id', $requestId);
    }
}
