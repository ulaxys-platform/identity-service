<?php

// Authentication and credentials are managed by Keycloak.
foreach (['actor', 'auth', 'profile', 'admin-users'] as $module) {
    require __DIR__."/api/v1/{$module}.php";
}
