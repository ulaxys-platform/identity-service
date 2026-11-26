<?php

use Illuminate\Support\Facades\Route;
use Ulaxys\Identity\Http\Controllers\HealthCheckController;

Route::prefix('healthCheck')->name('healthCheck/')->group(function () {
    Route::get('apiStatus', [HealthCheckController::class, 'getHealthCheck'])->name('apiStatus');
    Route::get('postgresStatus', [HealthCheckController::class, 'getPostgresCheck'])->name('postgresStatus');
});
