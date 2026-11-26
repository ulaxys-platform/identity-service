<?php

/*
|--------------------------------------------------------------------------
| uLaxys - Canonical OpenAPI Configuration
|--------------------------------------------------------------------------
|
| Shared by Laravel-based uLaxys services.
|
| Responsibilities of this service:
|   - Scan OpenAPI annotations
|   - Generate the OpenAPI document
|   - Store the generated specification
|
| Responsibilities intentionally NOT handled here:
|   - Swagger UI
|   - Documentation portal
|   - Gateway routing
|   - UI assets / themes
|   - Service discovery
|
| Those responsibilities belong to uLaxys MainGate.
|
*/

$appName = env('APP_NAME', 'uLaxys Service');
$appEnv = env('APP_ENV', 'production');

return [

    /*
    |--------------------------------------------------------------------------
    | Default Documentation
    |--------------------------------------------------------------------------
    */

    'default' => env(
        'L5_SWAGGER_DEFAULT_DOCUMENTATION',
        'default'
    ),

    /*
    |--------------------------------------------------------------------------
    | Documentation Definitions
    |--------------------------------------------------------------------------
    */

    'documentations' => [

        'default' => [

            /*
            |--------------------------------------------------------------------------
            | API Metadata
            |--------------------------------------------------------------------------
            */

            'api' => [
                'title' => env(
                    'L5_SWAGGER_API_TITLE',
                    $appName
                ),

                'version' => env(
                    'L5_SWAGGER_API_VERSION',
                    '1.0.0'
                ),

                'description' => env(
                    'L5_SWAGGER_API_DESCRIPTION',
                    $appName.' REST API.'
                ),
            ],

            /*
            |--------------------------------------------------------------------------
            | Package HTTP Routes
            |--------------------------------------------------------------------------
            |
            | Disabled intentionally.
            |
            | L5-Swagger is used only as an OpenAPI generator in downstream
            | services. Swagger UI is owned centrally by MainGate.
            |
            */

            'routes' => [
                'api' => null,
            ],

            /*
            |--------------------------------------------------------------------------
            | OpenAPI Generation
            |--------------------------------------------------------------------------
            */

            'paths' => [
                'use_absolute_path' => env(
                    'L5_SWAGGER_USE_ABSOLUTE_PATH',
                    true
                ),

                'docs_json' => 'api-docs.json',

                'docs_yaml' => 'api-docs.yaml',

                'format_to_use_for_docs' => env(
                    'L5_FORMAT_TO_USE_FOR_DOCS',
                    'json'
                ),

                'annotations' => [
                    base_path('app'),
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Defaults
    |--------------------------------------------------------------------------
    */

    'defaults' => [

        /*
        |--------------------------------------------------------------------------
        | L5-Swagger Routes
        |--------------------------------------------------------------------------
        |
        | All package-provided HTTP routes are disabled.
        |
        | The service exposes its generated OpenAPI document through the
        | application-owned /openapi.json endpoint instead.
        |
        */

        'routes' => [
            'docs' => null,

            'oauth2_callback' => null,

            'middleware' => [
                'api' => [],
                'asset' => [],
                'docs' => [],
                'oauth2_callback' => [],
            ],

            'group_options' => [],
        ],

        /*
        |--------------------------------------------------------------------------
        | Storage
        |--------------------------------------------------------------------------
        */

        'paths' => [
            'docs' => storage_path('api-docs'),

            /*
             * Kept for package compatibility.
             * No Swagger Blade views are used by uLaxys services.
             */
            'views' => base_path(
                'resources/views/vendor/l5-swagger'
            ),

            'base' => null, // Portable contract; APP_URL belongs to runtime only.

            'excludes' => [],
        ],

        /*
        |--------------------------------------------------------------------------
        | Scanner
        |--------------------------------------------------------------------------
        */

        'scanOptions' => [
            'open_api_spec_version' => '3.0.0',
            'analyser' => null,
            'analysis' => null,
            'processors' => [],
            'pattern' => null,
            'exclude' => [],
        ],

        /*
        |--------------------------------------------------------------------------
        | OpenAPI Security
        |--------------------------------------------------------------------------
        |
        | Security schemes belong in OpenAPI annotations.
        |
        */

        'securityDefinitions' => [
            'securitySchemes' => [],
            'security' => [],
        ],

        /*
        |--------------------------------------------------------------------------
        | Generation
        |--------------------------------------------------------------------------
        |
        | Production documentation is generated explicitly during build /
        | deployment. It is never regenerated on an HTTP request.
        |
        */

        'generate_always' => false,

        'generate_yaml_copy' => env(
            'L5_SWAGGER_GENERATE_YAML_COPY',
            false
        ),

        /*
        |--------------------------------------------------------------------------
        | Package Compatibility
        |--------------------------------------------------------------------------
        */

        'proxy' => false,

        'additional_config_url' => null,

        'operations_sort' => env(
            'L5_SWAGGER_OPERATIONS_SORT',
            'alpha'
        ),

        'validator_url' => null,

        /*
        |--------------------------------------------------------------------------
        | Constants
        |--------------------------------------------------------------------------
        */

        'constants' => [
            'L5_SWAGGER_CONST_HOST' => env(
                'L5_SWAGGER_CONST_HOST',
                env('APP_URL')
            ),
        ],
    ],
];
