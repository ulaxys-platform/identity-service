<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Ulaxys\Identity\Http\Controllers\Identity\AuthenticationController;
use Ulaxys\Identity\Http\Middleware\Identity\VerifyActorToken;

Route::prefix('api/v1/auth')->group(function (): void {
    Route::post('logout', [AuthenticationController::class, 'logout'])
        ->middleware([VerifyActorToken::class])
        ->name('identity/auth/logout');
});
