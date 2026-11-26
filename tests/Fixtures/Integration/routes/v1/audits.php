<?php

use Illuminate\Support\Facades\Route;
use Ulaxys\Identity\Tests\Fixtures\Integration\FixtureController;

Route::get('audits', [FixtureController::class, 'handle'])->middleware('service.signature')->name('fixture.audits.index');
