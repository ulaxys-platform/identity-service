<?php

namespace Ulaxys\Identity\Integration;

final class IntegrationMetadata
{
    public static function keys(array $value, array $required, array $optional = []): void
    {
        if (array_diff($required, array_keys($value)) || array_diff(array_keys($value), [...$required, ...$optional])) {
            throw new IntegrationException('Invalid integration metadata keys; consult docs/integration-generators.md.');
        }
    }

    public static function identifier(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,79}$/D', $value) !== 1) {
            throw new IntegrationException('Service, module, subject, action and operation identifiers must be bounded alphanumeric identifiers.');
        }

        return $value;
    }

    public static function phpIdentifier(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,119}$/D', $value)) {
            throw new IntegrationException('Native PHP identifiers must be bounded ASCII PHP identifiers.');
        }

        return $value;
    }

    public static function text(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '' || strlen($value) > 500 || preg_match('/[\x00-\x1F\x7F]/', $value) || ! mb_check_encoding($value, 'UTF-8')) {
            throw new IntegrationException('Descriptions/exemption reasons must be nonempty UTF-8 text without control characters.');
        }

        return $value;
    }

    public static function description(mixed $value): string|array
    {
        if (is_string($value)) {
            return self::text($value); // Legacy text/localization keys remain JSON-only.
        }
        if (! is_array($value)) {
            throw new IntegrationException('Permission action description must be text or explicit tr/en/ar translations.');
        }
        self::keys($value, ['tr', 'en', 'ar']);
        foreach ($value as $language => $text) {
            self::text($text);
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*(?:[.\/][A-Za-z0-9_-]+)+$/D', $text)) {
                throw new IntegrationException('Localization keys are not translated descriptions: '.$language);
            }
        }

        return ['tr' => $value['tr'], 'en' => $value['en'], 'ar' => $value['ar']];
    }

    public static function relativePath(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 240 || ! preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]*(?:/[A-Za-z0-9][A-Za-z0-9._-]*)*$~D', $value)
            || in_array('..', explode('/', $value), true) || in_array('.', explode('/', $value), true)) {
            throw new IntegrationException('Output paths/prefixes must be relative safe path segments, without traversal or symlinks.');
        }

        return $value;
    }

    public function validate(array $config): array
    {
        self::keys($config, ['schemaVersion', 'service', 'gateway', 'permissionsFile', 'modules']);
        if ($config['schemaVersion'] !== 1) {
            throw new IntegrationException('Unsupported integration schemaVersion; expected 1.');
        }
        self::identifier($config['service']);
        if (! is_array($config['gateway'])) {
            throw new IntegrationException('gateway must define both targets.');
        }
        self::keys($config['gateway'], ['mainGate', 'serviceGateway']);
        $files = ['gateway/compatibility.json', 'identity/compatibility.json'];
        foreach ($config['gateway'] as $name => $target) {
            if (! is_array($target)) {
                throw new IntegrationException('Invalid gateway target configuration.');
            }
            self::keys($target, ['prefix', 'file'], $name === 'mainGate' ? ['controller', 'destination'] : []);
            if (isset($target['controller'])) {
                self::phpIdentifier($target['controller']);
                if (! str_ends_with($target['controller'], 'Controller')) {
                    throw new IntegrationException('MainGate controller must end in Controller.');
                }
            }
            if (isset($target['destination'])) {
                self::identifier($target['destination']);
            }
            self::relativePath($target['prefix']);
            $files[] = self::relativePath($target['file']);
        }
        $files[] = self::relativePath($config['permissionsFile']);
        foreach ($files as $file) {
            if (! str_ends_with($file, '.json')) {
                throw new IntegrationException('Declaration destinations must remain JSON; native paths are renderer-owned.');
            }
        }
        if (count($files) !== count(array_unique($files))) {
            throw new IntegrationException('Output destination collision.');
        }
        foreach ($files as $left) {
            foreach ($files as $right) {
                if (str_starts_with($right, $left.'/')) {
                    throw new IntegrationException('Output file/directory collision.');
                }
            }
        }
        if (! is_array($config['modules']) || ! array_is_list($config['modules'])) {
            throw new IntegrationException('modules must be a list.');
        }
        $moduleIds = $subjects = $routeNames = [];
        foreach ($config['modules'] as $module) {
            if (! is_array($module)) {
                throw new IntegrationException('Each module must be an object.');
            }
            self::keys($module, ['id', 'permissions', 'operations']);
            $id = self::identifier($module['id']);
            if (isset($moduleIds[$id])) {
                throw new IntegrationException('Duplicate module identity.');
            }
            $moduleIds[$id] = true;
            $actions = [];
            if ($module['permissions'] !== null) {
                if (! is_array($module['permissions'])) {
                    throw new IntegrationException('Invalid permissions catalog.');
                }
                self::keys($module['permissions'], ['subject', 'description', 'actions']);
                $subject = self::identifier($module['permissions']['subject']);
                self::text($module['permissions']['description']);
                if (isset($subjects[$subject])) {
                    throw new IntegrationException('Duplicate permission subject.');
                }
                $subjects[$subject] = true;
                if (! is_array($module['permissions']['actions']) || ! array_is_list($module['permissions']['actions'])) {
                    throw new IntegrationException('actions must be a list.');
                }
                foreach ($module['permissions']['actions'] as $action) {
                    if (! is_array($action)) {
                        throw new IntegrationException('Invalid permission action.');
                    }
                    self::keys($action, ['action', 'description', 'module']);
                    $key = self::identifier($action['action']);
                    self::identifier($action['module']);
                    self::description($action['description']);
                    if (isset($actions[$key])) {
                        throw new IntegrationException('Duplicate permission action in subject '.$subject.'.');
                    }
                    $actions[$key] = true;
                }
            }
            if (! is_array($module['operations']) || ($module['operations'] !== [] && array_is_list($module['operations']))) {
                throw new IntegrationException('operations must map exact registered route names to semantic metadata.');
            }
            $operationIds = [];
            foreach ($module['operations'] as $name => $operation) {
                if (! is_string($name) || ! preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]{0,159}$~D', $name) || ! is_array($operation)) {
                    throw new IntegrationException('Invalid operation route name/metadata.');
                }
                self::keys($operation, ['operation', 'expose'], ['permission', 'exempt', 'gatewayAction']);
                if (isset($operation['gatewayAction'])) {
                    self::phpIdentifier($operation['gatewayAction']);
                }
                $operationId = self::identifier($operation['operation']);
                if (isset($operationIds[$operationId]) || isset($routeNames[$name])) {
                    throw new IntegrationException('Duplicate operation identity or conflicting route mapping.');
                }
                $operationIds[$operationId] = $routeNames[$name] = true;
                if (! is_bool($operation['expose']) || isset($operation['permission']) === isset($operation['exempt'])) {
                    throw new IntegrationException('Each operation needs explicit expose boolean and exactly one permission or exemption reason.');
                }
                if (isset($operation['permission'])) {
                    self::identifier($operation['permission']);
                    if (! isset($actions[$operation['permission']])) {
                        throw new IntegrationException('Operation references an undefined permission action: '.$name);
                    }
                } else {
                    self::text($operation['exempt']);
                }
            }
        }

        foreach ($config['modules'] as &$module) {
            if ($module['permissions'] !== null) {
                foreach ($module['permissions']['actions'] as &$action) {
                    $action['description'] = self::description($action['description']);
                }
                unset($action);
            }
        }
        unset($module);

        return $config;
    }
}
