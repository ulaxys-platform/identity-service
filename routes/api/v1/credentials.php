<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Ulaxys\Identity\Http\Controllers\Identity\CredentialController;
use Ulaxys\Identity\Http\Middleware\Identity\VerifyActorToken;

Route::prefix('api/v1/credentials')->group(function (): void {
    Route::post('password', [CredentialController::class, 'changePassword'])
        ->middleware([VerifyActorToken::class])
        ->name('identity/credentials/change-password');
    Route::post('forgot-password', [CredentialController::class, 'forgotPassword'])
        ->middleware('throttle:identity-recovery')
        ->name('identity/credentials/forgot-password');
    Route::post('reset-password', [CredentialController::class, 'resetPassword'])
        ->middleware('throttle:identity-recovery')
        ->name('identity/credentials/reset-password');
    Route::post('email-verification/start', [CredentialController::class, 'startEmailVerification'])
        ->middleware([VerifyActorToken::class])
        ->name('identity/credentials/email-verification/start');
    Route::post('email-verification/confirm', [CredentialController::class, 'confirmEmailVerification'])
        ->middleware('throttle:identity-recovery')
        ->name('identity/credentials/email-verification/confirm');
});
