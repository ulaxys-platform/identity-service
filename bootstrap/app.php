<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Ulaxys\Identity\Exceptions\Handler;
use Ulaxys\Identity\Http\Middleware\Language;
use Ulaxys\Identity\Http\Middleware\ValidateJsonBody;
use Ulaxys\Identity\Http\Middleware\VerifyServiceSignature;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(commands: __DIR__.'/../routes/console.php', using: function () {
        Route::middleware(['api', 'service.signature', 'json.body', 'lang'])->group(base_path('routes/api.php'));
        Route::middleware(['api', 'json.body', 'lang'])->group(base_path('routes/health.php'));
        Route::middleware(['api', 'service.signature'])->group(base_path('routes/openapi.php'));
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['json.body' => ValidateJsonBody::class, 'service.signature' => VerifyServiceSignature::class, 'lang' => Language::class]);
    })
    ->create();
$app->singleton(ExceptionHandler::class, Handler::class);

return $app;
