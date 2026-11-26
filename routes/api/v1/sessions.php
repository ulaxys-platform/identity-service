<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Ulaxys\Identity\Http\Controllers\Identity\SessionController;
use Ulaxys\Identity\Http\Middleware\Identity\VerifyActorToken;

// Literal segments are registered before the parameterized route so they can
// never be shadowed by it.
Route::prefix('api/v1/sessions')->middleware([VerifyActorToken::class])->group(function (): void {
    Route::get('/', [SessionController::class, 'index'])->name('identity/sessions/index');
    Route::delete('current', [SessionController::class, 'revokeCurrent'])->name('identity/sessions/revoke-current');
    Route::delete('all', [SessionController::class, 'revokeAllOthers'])->name('identity/sessions/revoke-all');
    Route::delete('{sessionId}', [SessionController::class, 'revoke'])->name('identity/sessions/revoke');
});
