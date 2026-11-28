<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Ulaxys\Identity\Http\Controllers\Identity\ProfileController;
use Ulaxys\Identity\Http\Middleware\Identity\VerifyActorToken;

Route::prefix('api/v1/profile')->middleware([VerifyActorToken::class])->group(function (): void {
    Route::get('me', [ProfileController::class, 'show'])->name('identity/profile/show');
    Route::patch('me', [ProfileController::class, 'update'])->name('identity/profile/update');
});
