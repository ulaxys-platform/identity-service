<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Ulaxys\Identity\Http\Controllers\Identity\AuthenticationController;
use Ulaxys\Identity\Http\Middleware\Identity\VerifyActorToken;

// Pre-authentication actions are Signature-gated and rate-limited; logout
// additionally requires a verified actor token. Middleware classes are
// referenced by fully-qualified name so parity-hashed engine files stay
// untouched (docs/template-defects.md section 6).
Route::prefix('api/v1/auth')->group(function (): void {
    Route::post('login', [AuthenticationController::class, 'login'])
        ->middleware('throttle:identity-login')
        ->name('identity/auth/login');
    Route::post('refresh', [AuthenticationController::class, 'refresh'])
        ->middleware('throttle:identity-refresh')
        ->name('identity/auth/refresh');
    Route::post('setup', [AuthenticationController::class, 'setup'])
        ->middleware('throttle:identity-recovery')
        ->name('identity/auth/setup');
    Route::post('logout', [AuthenticationController::class, 'logout'])
        ->middleware([VerifyActorToken::class])
        ->name('identity/auth/logout');
});
