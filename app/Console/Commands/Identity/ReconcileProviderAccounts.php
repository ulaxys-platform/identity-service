<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Console\Commands\Identity;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Ulaxys\Identity\Services\Identity\Provider\ManageProviderIdentity;
use Ulaxys\Identity\Services\Identity\Provider\ProviderFailure;
use Ulaxys\Identity\Services\Identity\Provider\ProvisionProviderIdentity;

final class ReconcileProviderAccounts extends Command
{
    protected $signature = 'identity:provider-reconcile {--limit=25 : Maximum queued requests to process}';

    protected $description = 'Retry queued provider lifecycle work';

    public function handle(ProvisionProviderIdentity $provisioning, ManageProviderIdentity $accounts): int
    {
        $batch = DB::table('identity_provider_mappings')
            ->where('issuer', config('keycloak.issuer'))
            ->where(function ($query): void {
                $query->whereIn('state', ['provisioning', 'retry_required', 'disable_pending', 'suspend_pending'])
                    ->orWhere(function ($delayed): void {
                        $delayed->whereIn('state', ['activation_pending', 'reactivation_pending'])
                            ->where('updated_at', '<=', now()->subMinute());
                    });
            })
            ->orderBy('updated_at')->orderBy('request_id')
            ->limit(max(1, min(100, (int) $this->option('limit'))))->get();
        $failed = false;
        foreach ($batch as $row) {
            try {
                match ($row->state) {
                    'disable_pending', 'suspend_pending', 'reactivation_pending', 'activation_pending' => $accounts->reconcile($row->user_id, $row->state),
                    default => $provisioning->stage($row->request_id),
                };
            } catch (ProviderFailure) {
                $failed = true;
                DB::table('identity_provider_mappings')->where('request_id', $row->request_id)
                    ->where('state', $row->state)
                    ->update(['updated_at' => now()]);
                $this->warn('Pending provider request '.$row->request_id);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
