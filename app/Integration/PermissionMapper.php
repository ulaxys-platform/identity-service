<?php

namespace Ulaxys\Identity\Integration;

final class PermissionMapper
{
    public function map(array $discovery, array $config, bool $strict): array
    {
        $byName = $catalog = $used = $subjects = $warnings = [];
        foreach ($config['modules'] as $module) {
            foreach ($module['operations'] as $name => $metadata) {
                $byName[$name] = ['module' => $module['id'], 'metadata' => $metadata, 'subject' => $module['permissions']['subject'] ?? null];
            }
            if ($module['permissions'] !== null) {
                $subject = $module['permissions']['subject'];
                $subjects[$subject] = $module['permissions'];
                foreach ($module['permissions']['actions'] as $action) {
                    $catalog[$subject][$action['action']] = ['action' => $action['action'], 'description' => $action['description'], 'module' => $action['module']];
                }
            }
        }
        $routes = [];
        foreach ($discovery['routes'] as $route) {
            $mapping = $byName[$route['name']] ?? null;
            if ($mapping === null) {
                throw new IntegrationException('Missing integration metadata for registered operation '.$route['name'].'. Map it explicitly or provide an exemption reason.');
            }
            unset($byName[$route['name']]);
            $metadata = $mapping['metadata'];
            $protected = $route['requiresSignature'];
            if (! $protected && ($metadata['expose'] || isset($metadata['permission']))) {
                throw new IntegrationException('Public operation must be explicitly exempt and unexposed: '.$route['name']);
            }
            $permission = null;
            if (isset($metadata['permission'])) {
                $subject = $mapping['subject'];
                $action = $metadata['permission'];
                $used[$subject][$action] = true;
                $permission = ['subject' => $subject, 'action' => $action];
            }
            $routes[] = [...$route, 'module' => $mapping['module'], 'operation' => $metadata['operation'], 'expose' => $metadata['expose'],
                'permission' => $permission, 'exemption' => $metadata['exempt'] ?? null,
                ...(isset($metadata['gatewayAction']) ? ['gatewayAction' => $metadata['gatewayAction']] : [])];
        }
        if ($byName !== []) {
            throw new IntegrationException('Metadata references unregistered operations: '.implode(', ', array_keys($byName)));
        }
        $permissions = [];
        ksort($subjects);
        foreach ($subjects as $subject => $definition) {
            $actions = $catalog[$subject] ?? [];
            ksort($actions);
            $selected = [];
            foreach ($actions as $name => $action) {
                if (! isset($used[$subject][$name])) {
                    $warnings[] = 'Unused permission action '.$subject.'/'.$name;

                    continue;
                }
                $selected[] = $action;
            }
            if ($selected !== []) {
                $permissions[] = ['subject' => $subject, 'description' => $definition['description'], 'actions' => $selected];
            }
        }
        if ($strict && $warnings !== []) {
            throw new IntegrationException('Strict validation: '.implode('; ', $warnings));
        }

        return ['service' => $config['service'], 'routes' => $routes, 'excluded' => $discovery['excluded'], 'permissions' => $permissions, 'warnings' => $warnings];
    }
}
