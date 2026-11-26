<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Ulaxys\Identity\Http\Controllers\Identity\OAuthContractController;

// Internal Gateway token-validation contract endpoints (NOT public OAuth
// protocol endpoints; see ADR-0001 and docs/gateway-integration.md).
Route::prefix('api/v1/oauth')->group(function (): void {
    Route::get('jwks', [OAuthContractController::class, 'jwks'])->name('identity/oauth/jwks');
    Route::post('introspect', [OAuthContractController::class, 'introspect'])
        ->middleware('throttle:identity-introspect')
        ->name('identity/oauth/introspect');
    Route::post('revoke', [OAuthContractController::class, 'revoke'])->name('identity/oauth/revoke');
});
