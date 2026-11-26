<?php

// Explicit modules keep registration order and middleware boundaries reviewable.
foreach ([] as $module) {
    require __DIR__."/api/v1/{$module}.php";
}
