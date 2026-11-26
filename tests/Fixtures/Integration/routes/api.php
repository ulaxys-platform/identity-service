<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'json.body', 'lang'])->group(function () {
    foreach (['records', 'audits'] as $module) {
        require __DIR__.'/v1/'.$module.'.php';
    }
});
