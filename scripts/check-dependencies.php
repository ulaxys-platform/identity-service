<?php

use Illuminate\Contracts\Console\Kernel;
use Ulaxys\Identity\Support\DependencyVerification;

require __DIR__.'/../vendor/autoload.php';

try {
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    exit((new DependencyVerification)->run($app, config('dependencies'), $argv[1] ?? '', static function (string $line): void {
        echo $line;
    }));
} catch (Throwable) {
    fwrite(STDERR, "Dependency verification could not complete; review local configuration.\n");
    exit(2);
}
