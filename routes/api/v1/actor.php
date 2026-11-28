<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Ulaxys\Identity\Http\Controllers\Identity\ActorContextController;
use Ulaxys\Identity\Http\Middleware\Identity\VerifyActorToken;

Route::prefix('api/v1/actor')->group(function (): void {
    Route::get('context', [ActorContextController::class, 'show'])
        ->middleware([VerifyActorToken::class])
        ->name('identity/actor/context');
});
