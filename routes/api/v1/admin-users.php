<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Ulaxys\Identity\Http\Controllers\Identity\AdminUserController;
use Ulaxys\Identity\Http\Middleware\Identity\RequirePlatformAdministrator;
use Ulaxys\Identity\Http\Middleware\Identity\VerifyActorToken;

// Deny-by-default interim administration (ADR-0004).
Route::prefix('api/v1/admin/users')->middleware([VerifyActorToken::class, RequirePlatformAdministrator::class])->group(function (): void {
    Route::get('/', [AdminUserController::class, 'index'])->name('identity/admin-users/index');
    Route::post('/', [AdminUserController::class, 'store'])->name('identity/admin-users/create');
    Route::get('{userId}', [AdminUserController::class, 'show'])->name('identity/admin-users/show');
});
