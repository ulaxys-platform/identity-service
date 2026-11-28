<?php

// Versioned, semantic metadata only. HTTP methods and paths come from Laravel.
// Every mounted Identity V2 route is declared here with either an explicit
// permission (subject/action/module with tr/en/ar descriptions) or an explicit
// exemption reason, so the integration generators and the future RBAC engine
// consume a complete, reviewable catalog.
use Ulaxys\Identity\Http\Middleware\Identity\RequirePlatformAdministrator;
use Ulaxys\Identity\Http\Middleware\Identity\VerifyActorToken;

return [
    'schemaVersion' => 2,
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
                'identity/auth/logout' => ['operation' => 'logout', 'gatewayAction' => 'logout', 'permission' => 'logout', 'expose' => true, 'securityMiddleware' => [['kind' => 'actor', 'middleware' => VerifyActorToken::class]]],
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
                'identity/profile/show' => ['operation' => 'showProfile', 'gatewayAction' => 'showProfile', 'permission' => 'show', 'expose' => true, 'securityMiddleware' => [['kind' => 'actor', 'middleware' => VerifyActorToken::class]]],
                'identity/profile/update' => ['operation' => 'updateProfile', 'gatewayAction' => 'updateProfile', 'permission' => 'update', 'expose' => true, 'securityMiddleware' => [['kind' => 'actor', 'middleware' => VerifyActorToken::class]]],
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
                    ['action' => 'lifecycle', 'description' => ['tr' => 'Hesap yaşam döngüsünü yönetir', 'en' => 'Manage account lifecycle', 'ar' => 'إدارة دورة حياة الحساب'], 'module' => 'identity-admin-users'],
                ],
            ],
            'operations' => [
                'identity/admin-users/index' => ['operation' => 'listUsers', 'gatewayAction' => 'listUsers', 'permission' => 'index', 'expose' => true, 'securityMiddleware' => [['kind' => 'actor', 'middleware' => VerifyActorToken::class], ['kind' => 'authorization', 'middleware' => RequirePlatformAdministrator::class]]],
                'identity/admin-users/create' => ['operation' => 'createUser', 'gatewayAction' => 'createUser', 'permission' => 'create', 'expose' => true, 'securityMiddleware' => [['kind' => 'actor', 'middleware' => VerifyActorToken::class], ['kind' => 'authorization', 'middleware' => RequirePlatformAdministrator::class]]],
                'identity/admin-users/show' => ['operation' => 'showUser', 'gatewayAction' => 'showUser', 'permission' => 'read', 'expose' => true, 'securityMiddleware' => [['kind' => 'actor', 'middleware' => VerifyActorToken::class], ['kind' => 'authorization', 'middleware' => RequirePlatformAdministrator::class]]],
                'identity/admin-users/lifecycle' => ['operation' => 'manageLifecycle', 'gatewayAction' => 'manageLifecycle', 'permission' => 'lifecycle', 'expose' => false, 'securityMiddleware' => [['kind' => 'actor', 'middleware' => VerifyActorToken::class], ['kind' => 'authorization', 'middleware' => RequirePlatformAdministrator::class]]],
            ],
        ],
        [
            'id' => 'identity-actor',
            'permissions' => null,
            'operations' => [
                'identity/actor/context' => ['operation' => 'actorContext', 'gatewayAction' => 'actorContext', 'exempt' => 'Gateway integration contract endpoint; any verified actor may read its own context, no permission decision is involved.', 'expose' => true, 'securityMiddleware' => [['kind' => 'actor', 'middleware' => VerifyActorToken::class]]],
            ],
        ],
    ],
];
