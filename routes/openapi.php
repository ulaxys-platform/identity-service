<?php

use Illuminate\Support\Facades\Route;
use Ulaxys\Identity\Http\Controllers\Documentation\OpenApiController;

Route::get(
    '/openapi.json',
    OpenApiController::class
)->name('openapi');
