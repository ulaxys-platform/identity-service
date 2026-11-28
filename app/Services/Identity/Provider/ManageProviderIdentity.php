<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity\Provider;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ulaxys\Identity\Models\User;
use Ulaxys\Identity\Repositories\RepositoryInterface\PlatformAdministratorRepositoryInterface;
use Ulaxys\Identity\Services\Identity\AuditLogger;

final class ManageProviderIdentity
{
    public function __construct(
        private readonly KeycloakAdministration $provider,
        private readonly PlatformAdministratorRepositoryInterface $administrators,
        private readonly AuditLogger $audit,
        private readonly ProviderLifecycleLock $lock,
    ) {}

    public function invite(string $id, ?string $actorId = null): void
    {
        $this->lock->run($id, fn () => $this->inviteLocked($id, $actorId));
    }

    private function inviteLocked(string $id, ?string $actorId = null): void
    {
        $mapping = $this->mapping($id);
        if (! is_string($mapping->contact_email)) {
            throw new ProviderFailure('A staged contact email is required for activation delivery.');
        }
        DB::transaction(function () use ($id, $actorId): void {
            $latest = $this->mappings($id)->lockForUpdate()->first();
            if (! in_array($latest->state, ['staged_disabled', 'activation_pending'], true)) {
                throw new ProviderFailure('Account is not eligible for activation delivery.');
            }
            if ($latest->state === 'staged_disabled') {
                $this->mappings($id)->update(['state' => 'activation_pending', 'updated_at' => now()]);
                $this->audit->audit('identity.provider.activation.requested', $actorId, $actorId === null ? 'operator' : 'user', 'user', $id, 'success');
            }
        });
        if ($mapping->invitation_accepted_at !== null) {
            return;
        }
        $this->provider->inviteAccount($mapping->subject, $id, $mapping->contact_email);
        DB::transaction(function () use ($id, $actorId): void {
            $this->mappings($id)->where('state', 'activation_pending')
                ->update(['invitation_accepted_at' => now(), 'updated_at' => now()]);
            $this->audit->audit('identity.provider.activation.accepted', $actorId, $actorId === null ? 'operator' : 'user', 'user', $id, 'success');
        });
    }

    public function activate(string $id, bool $bootstrap, ?string $actorId = null): void
    {
        $this->lock->run($id, fn () => $this->activateLocked($id, $bootstrap, $actorId));
    }

    private function activateLocked(string $id, bool $bootstrap, ?string $actorId = null, bool $reactivation = false): void
    {
        $mapping = $this->mapping($id);
        if (! in_array($mapping->state, $reactivation ? ['reactivation_pending'] : ['staged_disabled', 'activation_pending', 'active'], true)) {
            throw new ProviderFailure('Account is not ready for activation.');
        }
        $this->provider->verifyActivatedAccount($mapping->subject, $id, $mapping->contact_email);
        DB::transaction(function () use ($id, $mapping, $bootstrap, $actorId): void {
            $latest = $this->mappings($id)->lockForUpdate()->first();
            if ($latest->state !== $mapping->state) {
                throw new ProviderFailure('Account state changed during activation.');
            }
            if ($latest->state !== 'active') {
                $user = User::query()->lockForUpdate()->findOrFail($id);
                if (! in_array($user->status, [User::STATUS_INVITED, User::STATUS_DISABLED, User::STATUS_SUSPENDED], true)
                    || $user->type !== User::TYPE_HUMAN) {
                    throw new ProviderFailure('Platform account is not eligible for activation.');
                }
                $user->update(['status' => User::STATUS_ACTIVE]);
                $this->mappings($id)->update(['state' => 'active', 'updated_at' => now()]);
                $this->audit->audit('identity.provider.activated', $actorId, $actorId === null ? 'operator' : 'user', 'user', $id, 'success');
            }
            if ($bootstrap) {
                $this->administrators->lockBootstrap();
                if ($this->administrators->findActiveByUserId($id) !== null) {
                    return;
                }
                if ($this->administrators->hasAnyGrant()) {
                    throw new ProviderFailure('Initial administrator grant has already been assigned.');
                }
                $this->administrators->create(['user_id' => $id, 'granted_at' => now(), 'grant_reason' => 'Verified provider bootstrap']);
                $this->audit->audit('identity.provider.bootstrap', null, 'operator', 'user', $id, 'success');
            }
        });
    }

    public function disable(string $id, bool $suspend = false, ?string $actorId = null): void
    {
        $this->lock->run($id, fn () => $this->disableLocked($id, $suspend, $actorId));
    }

    private function disableLocked(string $id, bool $suspend = false, ?string $actorId = null): void
    {
        $mapping = $this->mapping($id);
        $final = $suspend ? 'suspended' : 'disabled';
        $pendingState = $suspend ? 'suspend_pending' : 'disable_pending';
        $pending = DB::transaction(function () use ($id, $pendingState, $final, $suspend, $actorId): bool {
            $latest = $this->mappings($id)->lockForUpdate()->first();
            if ($latest->state === $final) {
                return false;
            }
            if (! in_array($latest->state, ['active', 'staged_disabled', 'activation_pending', 'reactivation_pending', $pendingState], true)) {
                throw new ProviderFailure('Account is not eligible for disablement.');
            }
            if ($latest->state !== $pendingState) {
                User::query()->findOrFail($id)->update(['status' => $suspend ? User::STATUS_SUSPENDED : User::STATUS_DISABLED]);
                $this->mappings($id)->update(['state' => $pendingState, 'updated_at' => now()]);
                $this->audit->audit('identity.provider.disable.requested', $actorId, $actorId === null ? 'operator' : 'user', 'user', $id, 'success');
            }

            return true;
        });
        if (! $pending) {
            return;
        }

        $this->provider->disableAccount($mapping->subject, $id);
        DB::transaction(function () use ($id, $pendingState, $final, $actorId): void {
            if ($this->mappings($id)->where('state', $pendingState)
                ->update(['state' => $final, 'updated_at' => now()]) === 1) {
                $this->audit->audit('identity.provider.disabled', $actorId, $actorId === null ? 'operator' : 'user', 'user', $id, 'success');
            }
        });
    }

    public function reactivate(string $id, ?string $actorId = null): void
    {
        $this->lock->run($id, fn () => $this->reactivateLocked($id, $actorId));
    }

    public function reconcile(string $id, string $expectedState): void
    {
        $this->lock->run($id, function () use ($id, $expectedState): void {
            $mapping = $this->mapping($id);
            if ($mapping->state !== $expectedState) {
                return;
            }

            match ($expectedState) {
                'disable_pending', 'suspend_pending' => $this->disableLocked($id, $expectedState === 'suspend_pending'),
                'reactivation_pending' => $this->reactivateLocked($id, null),
                'activation_pending' => $mapping->invitation_accepted_at === null
                    ? $this->inviteLocked($id)
                    : $this->activateLocked($id, false),
                default => throw new ProviderFailure('Unknown pending lifecycle state.'),
            };
        });
    }

    private function reactivateLocked(string $id, ?string $actorId): void
    {
        $mapping = $this->mapping($id);
        if (! in_array($mapping->state, ['disabled', 'suspended', 'reactivation_pending'], true)) {
            throw new ProviderFailure('Account is not eligible for reactivation.');
        }

        DB::transaction(function () use ($id, $actorId): void {
            if ($this->mappings($id)->whereIn('state', ['disabled', 'suspended'])
                ->update(['state' => 'reactivation_pending', 'updated_at' => now()]) === 1) {
                $this->audit->audit('identity.provider.reactivation.requested', $actorId, $actorId === null ? 'operator' : 'user', 'user', $id, 'success');
            }
        });
        $this->provider->reactivateAccount($mapping->subject, $id, $mapping->contact_email);
        $this->activateLocked($id, false, $actorId, reactivation: true);
    }

    private function mapping(string $id): object
    {
        if (! Str::isUlid($id)) {
            throw new ProviderFailure('A platform user ULID is required.');
        }
        $mapping = $this->mappings($id)->first();
        if ($mapping === null || $mapping->subject === null || $mapping->issuer !== $this->provider->issuer()) {
            throw new ProviderFailure('No staged account for the trusted issuer.');
        }

        return $mapping;
    }

    private function mappings(string $id): Builder
    {
        return DB::table('identity_provider_mappings')->where('user_id', $id);
    }
}
