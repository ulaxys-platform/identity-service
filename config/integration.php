<?php

// Versioned, semantic metadata only. HTTP methods and paths come from Laravel.
// Every mounted Identity V2 route is declared here with either an explicit
// permission (subject/action/module with tr/en/ar descriptions) or an explicit
// exemption reason, so the integration generators and the future RBAC engine
// consume a complete, reviewable catalog.
return [
    'schemaVersion' => 1,
    'service' => 'identity',
    'gateway' => [
        'mainGate' => ['prefix' => 'services/identity', 'file' => 'gateway/maingate.routes.json'],
        'serviceGateway' => ['prefix' => 'identity', 'file' => 'gateway/service-gateway.routes.json'],
    ],
    'permissionsFile' => 'identity/permissions.json',
    'modules' => [
        [
            'id' => 'identity-auth',
            'permissions' => [
                'subject' => 'identity-auth',
                'description' => 'Identity authentication session lifecycle',
                'actions' => [
                    ['action' => 'logout', 'description' => ['tr' => 'Kendi aktif oturumunu güvenli şekilde kapatır', 'en' => 'Securely end the current session', 'ar' => 'إنهاء الجلسة الحالية بشكل آمن'], 'module' => 'identity-auth'],
                ],
            ],
            'operations' => [
                'identity/auth/login' => ['operation' => 'login', 'gatewayAction' => 'login', 'exempt' => 'Pre-authentication endpoint; protected by the service signature, login rate limiting and enumeration-resistant responses.', 'expose' => true],
                'identity/auth/refresh' => ['operation' => 'refreshToken', 'gatewayAction' => 'refresh', 'exempt' => 'Pre-authentication endpoint; presents a single-use rotating refresh token and is rate limited.', 'expose' => true],
                'identity/auth/setup' => ['operation' => 'setupAccount', 'gatewayAction' => 'setup', 'exempt' => 'Pre-authentication endpoint; consumes a single-use bootstrap setup token issued by the auditable identity:bootstrap-admin command.', 'expose' => true],
                'identity/auth/logout' => ['operation' => 'logout', 'gatewayAction' => 'logout', 'permission' => 'logout', 'expose' => true],
            ],
        ],
        [
            'id' => 'identity-sessions',
            'permissions' => [
                'subject' => 'identity-sessions',
                'description' => 'Identity session management',
                'actions' => [
                    ['action' => 'index', 'description' => ['tr' => 'Kendi aktif oturumlarını listeler', 'en' => 'List own active sessions', 'ar' => 'عرض جلسات المستخدم النشطة'], 'module' => 'identity-sessions'],
                    ['action' => 'revoke', 'description' => ['tr' => 'Kendi oturumlarını iptal eder', 'en' => 'Revoke own sessions', 'ar' => 'إلغاء جلسات المستخدم'], 'module' => 'identity-sessions'],
                ],
            ],
            'operations' => [
                'identity/sessions/index' => ['operation' => 'listSessions', 'gatewayAction' => 'listSessions', 'permission' => 'index', 'expose' => true],
                'identity/sessions/revoke-current' => ['operation' => 'revokeCurrentSession', 'gatewayAction' => 'revokeCurrentSession', 'permission' => 'revoke', 'expose' => true],
                'identity/sessions/revoke-all' => ['operation' => 'revokeAllOtherSessions', 'gatewayAction' => 'revokeAllSessions', 'permission' => 'revoke', 'expose' => true],
                'identity/sessions/revoke' => ['operation' => 'revokeSession', 'gatewayAction' => 'revokeSession', 'permission' => 'revoke', 'expose' => true],
            ],
        ],
        [
            'id' => 'identity-credentials',
            'permissions' => [
                'subject' => 'identity-credentials',
                'description' => 'Identity credential lifecycle',
                'actions' => [
                    ['action' => 'change', 'description' => ['tr' => 'Kendi şifresini değiştirir', 'en' => 'Change own password', 'ar' => 'تغيير كلمة المرور الخاصة'], 'module' => 'identity-credentials'],
                    ['action' => 'verify', 'description' => ['tr' => 'E-posta doğrulamasını başlatır', 'en' => 'Start own email verification', 'ar' => 'بدء تأكيد البريد الإلكتروني'], 'module' => 'identity-credentials'],
                ],
            ],
            'operations' => [
                'identity/credentials/change-password' => ['operation' => 'changePassword', 'gatewayAction' => 'changePassword', 'permission' => 'change', 'expose' => true],
                'identity/credentials/forgot-password' => ['operation' => 'forgotPassword', 'gatewayAction' => 'forgotPassword', 'exempt' => 'Pre-authentication endpoint; responds identically regardless of account existence and is rate limited.', 'expose' => true],
                'identity/credentials/reset-password' => ['operation' => 'resetPassword', 'gatewayAction' => 'resetPassword', 'exempt' => 'Pre-authentication endpoint; consumes a single-use hashed recovery token and is rate limited.', 'expose' => true],
                'identity/credentials/email-verification/start' => ['operation' => 'startEmailVerification', 'gatewayAction' => 'startEmailVerification', 'permission' => 'verify', 'expose' => true],
                'identity/credentials/email-verification/confirm' => ['operation' => 'confirmEmailVerification', 'gatewayAction' => 'confirmEmailVerification', 'exempt' => 'Pre-authentication endpoint; consumes a single-use hashed verification token and is rate limited.', 'expose' => true],
            ],
        ],
        [
            'id' => 'identity-profile',
            'permissions' => [
                'subject' => 'identity-profile',
                'description' => 'Identity profile of the authenticated actor',
                'actions' => [
                    ['action' => 'show', 'description' => ['tr' => 'Kendi profilini görüntüler', 'en' => 'View own profile', 'ar' => 'عرض الملف الشخصي الخاص'], 'module' => 'identity-profile'],
                    ['action' => 'update', 'description' => ['tr' => 'Kendi profilini günceller', 'en' => 'Update own profile', 'ar' => 'تحديث الملف الشخصي الخاص'], 'module' => 'identity-profile'],
                ],
            ],
            'operations' => [
                'identity/profile/show' => ['operation' => 'showProfile', 'gatewayAction' => 'showProfile', 'permission' => 'show', 'expose' => true],
                'identity/profile/update' => ['operation' => 'updateProfile', 'gatewayAction' => 'updateProfile', 'permission' => 'update', 'expose' => true],
            ],
        ],
        [
            'id' => 'identity-admin-users',
            'permissions' => [
                'subject' => 'identity-admin-users',
                'description' => 'Platform user administration (interim I1 capability)',
                'actions' => [
                    ['action' => 'index', 'description' => ['tr' => 'Kullanıcı listesini görüntüler', 'en' => 'List platform users', 'ar' => 'عرض قائمة المستخدمين'], 'module' => 'identity-admin-users'],
                    ['action' => 'read', 'description' => ['tr' => 'Kullanıcı detayını görüntüler', 'en' => 'View platform user details', 'ar' => 'عرض تفاصيل المستخدم'], 'module' => 'identity-admin-users'],
                    ['action' => 'create', 'description' => ['tr' => 'Yeni kullanıcı oluşturur', 'en' => 'Create a platform user', 'ar' => 'إنشاء مستخدم جديد'], 'module' => 'identity-admin-users'],
                ],
            ],
            'operations' => [
                'identity/admin-users/index' => ['operation' => 'listUsers', 'gatewayAction' => 'listUsers', 'permission' => 'index', 'expose' => true],
                'identity/admin-users/create' => ['operation' => 'createUser', 'gatewayAction' => 'createUser', 'permission' => 'create', 'expose' => true],
                'identity/admin-users/show' => ['operation' => 'showUser', 'gatewayAction' => 'showUser', 'permission' => 'read', 'expose' => true],
            ],
        ],
        [
            'id' => 'identity-actor',
            'permissions' => null,
            'operations' => [
                'identity/actor/context' => ['operation' => 'actorContext', 'gatewayAction' => 'actorContext', 'exempt' => 'Gateway integration contract endpoint; any verified actor may read its own context, no permission decision is involved.', 'expose' => true],
            ],
        ],
        [
            'id' => 'identity-oauth-contract',
            'permissions' => null,
            'operations' => [
                'identity/oauth/jwks' => ['operation' => 'jwks', 'gatewayAction' => 'jwks', 'exempt' => 'Gateway token-validation contract endpoint; publishes public signing keys only, gated by the service signature.', 'expose' => true],
                'identity/oauth/introspect' => ['operation' => 'introspectToken', 'gatewayAction' => 'introspect', 'exempt' => 'Gateway token-validation contract endpoint (RFC 7662 semantics); machine-to-machine, gated by the service signature and rate limiting.', 'expose' => true],
                'identity/oauth/revoke' => ['operation' => 'revokeToken', 'gatewayAction' => 'revokeToken', 'exempt' => 'Gateway token-revocation contract endpoint (RFC 7009 semantics); machine-to-machine, gated by the service signature.', 'expose' => true],
            ],
        ],
    ],
];
