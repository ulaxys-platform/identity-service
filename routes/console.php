<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('identity:provider-reconcile')->everyMinute()->withoutOverlapping()
    ->when(fn (): bool => is_string(config('keycloak.issuer')) && config('keycloak.issuer') !== '');
