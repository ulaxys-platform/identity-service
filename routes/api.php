<?php

// Explicit modules keep registration order and middleware boundaries reviewable.
// Identity V2 module set (I1): actor contract, authentication, sessions,
// credentials, profile, interim user administration and the Gateway
// token-validation contract. See docs/gateway-integration.md.
foreach (['actor', 'auth', 'sessions', 'credentials', 'profile', 'admin-users', 'oauth'] as $module) {
    require __DIR__."/api/v1/{$module}.php";
}
