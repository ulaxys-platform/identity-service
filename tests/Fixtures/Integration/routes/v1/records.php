<?php

use Illuminate\Support\Facades\Route;
use Ulaxys\Identity\Tests\Fixtures\Integration\FixtureController;

Route::prefix('records')->name('fixture.records.')->group(function () {
    Route::middleware('service.signature')->group(function () {
        Route::get('/', [FixtureController::class, 'handle'])->name('index');
        Route::post('search', [FixtureController::class, 'handle'])->name('search');
        Route::post('/', [FixtureController::class, 'handle'])->name('store');
        Route::match(['HEAD'], 'status', [FixtureController::class, 'handle'])->name('status');
        Route::post('synchronize', [FixtureController::class, 'handle'])->name('synchronize');
        Route::get('{record}/children/{child}', [FixtureController::class, 'handle'])->where(['record' => '[0-9]+', 'child' => '[a-z]+'])->name('show');
        Route::match(['PUT', 'PATCH'], '{record}', [FixtureController::class, 'handle'])->name('update');
        Route::delete('{record}', [FixtureController::class, 'handle'])->name('destroy');
    });
    Route::get('public-state', [FixtureController::class, 'handle'])->name('public');
});
