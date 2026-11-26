<?php

namespace Ulaxys\Identity\Integration;

use Illuminate\Support\Str;

/** Inventory's data/catalog shape with a reviewed, description-only shared writer. */
final class NativeIdentityRenderer
{
    public function hasTranslations(array $snapshot): bool
    {
        foreach ($snapshot['permissions'] as $subject) {
            foreach ($subject['actions'] as $action) {
                if (! is_array($action['description'])) {
                    return false;
                }
            }
        }

        return true;
    }

    public function generate(array $snapshot, array $config): array
    {
        $catalog = $keys = $identities = [];
        foreach ($snapshot['permissions'] as $subject) {
            foreach ($subject['actions'] as $action) {
                foreach ([$subject['subject'], $action['action'], $action['module']] as $identifier) {
                    if (! preg_match('/^[A-Za-z][A-Za-z0-9-]{0,79}$/D', $identifier)) {
                        throw new IntegrationException('Native Identity identifiers must match the Inventory middleware convention (letters, digits, hyphens): '.$identifier);
                    }
                }
                $name = $action['action'].'-'.$subject['subject'];
                if (isset($identities[$name])) {
                    throw new IntegrationException('Duplicate Identity identity or canonical middleware-name collision: '.$name);
                }
                if (! is_array($action['description'])) {
                    throw new IntegrationException('Native Identity needs explicit tr/en/ar translations for '.$name.'. Add a description language map or use --permission-format=json for legacy descriptions/localization keys.');
                }
                $description = IntegrationMetadata::description($action['description']);
                $data = ['action' => $action['action'], 'subject' => $subject['subject'], 'module' => $action['module'], 'description' => $description];
                $catalog[$name] = ['data' => $data];
                $keys[$name] = ['name' => $name, 'action' => $action['action'], 'subject' => $subject['subject']];
                $identities[$name] = [$action['module'], $subject['subject'], $action['action']];
            }
        }
        // Validate every mapped reference, including protected non-exposed operations.
        foreach ($snapshot['routes'] as $route) {
            if ($route['permission'] === null) {
                if ($route['expose']) {
                    throw new IntegrationException('Exposed operation has no Identity permission: '.$route['name']);
                }

                continue;
            }
            $name = $route['permission']['action'].'-'.$route['permission']['subject'];
            if (! isset($identities[$name]) || array_slice($identities[$name], 1) !== [$route['permission']['subject'], $route['permission']['action']]) {
                throw new IntegrationException('Mapped permission is missing from Identity catalog: '.$route['name']);
            }
        }
        ksort($catalog, SORT_STRING);
        ksort($keys, SORT_STRING);
        $service = $config['service'];
        $class = IntegrationMetadata::phpIdentifier(Str::studly($service).'PermissionSeeder');
        $base = 'identity/database/seeders/';
        $files = [];
        $add = function (string $path, string $contents) use (&$files): void {
            $files[$path] = NativeIdentityFile::fromRenderer($path, $contents);
        };
        $add($base.'Permissions/'.$class.'.php', "<?php\n\nnamespace Database\\Seeders\\Permissions;\n\nuse Database\\Seeders\\Support\\PermissionSeedWriter;\nuse Illuminate\\Database\\Seeder;\n\nclass {$class} extends Seeder\n{\n    public function run(): void\n    {\n        PermissionSeedWriter::seed(__DIR__.'/../Support/permissions-{$service}.php');\n    }\n}\n");
        $writer = file_get_contents(__DIR__.'/Stubs/PermissionSeedWriter.php.stub');
        if ($writer === false) {
            throw new IntegrationException('Reviewed Identity writer template is unavailable.');
        }
        // Force canonical output bytes independent of Windows/macOS/Linux checkout line endings.
        // The reviewed template is LF in Git; normalization does not change PHP semantics.
        $writer = str_replace(["\r\n", "\r"], "\n", $writer);
        $add($base.'Support/PermissionSeedWriter.php', $writer);
        $add($base.'Support/permissions-'.$service.'.php', "<?php\n\nreturn ".$this->php(array_values($catalog)).";\n");
        $add($base.'Support/permissions-'.$service.'-keys.json', json_encode(['schema_version' => 1, 'service' => $service,
            'permissions' => array_values($keys)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        $add('identity/README.md', $this->readme($service, $class));

        return $files;
    }

    private function php(mixed $value, int $depth = 0): string
    {
        if (! is_array($value)) {
            return var_export($value, true);
        }
        if ($value === []) {
            return '[]';
        }
        $lines = [];
        foreach ($value as $key => $item) {
            $lines[] = str_repeat('    ', $depth + 1).(array_is_list($value) ? '' : var_export($key, true).' => ').$this->php($item, $depth + 1).',';
        }

        return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $depth).']';
    }

    private function readme(string $service, string $class): string
    {
        return <<<MD
# {$service} — native uLaxys PHP Identity permission package

Installation is manual. This package was tested with isolated database fixtures, not the actual Identity application.

1. Review the diff, permission identities, tr/en/ar descriptions and the existing Identity table/defaults/constraints. Back up and record existing permission IDs, role references, grants, model and active values. Use an isolated staging database first.
2. Copy service-specific `database/seeders/Permissions/{$class}.php` and `database/seeders/Support/permissions-{$service}.php` into the same paths in Identity. The keys JSON is a review artifact, not a second permission source or executable loader.
3. **Shared writer:** review `database/seeders/Support/PermissionSeedWriter.php` separately. Its PSR-4 namespace is `Database\Seeders\Support`, API `PermissionSeedWriter::seed(string \$catalog): void`, contract v1: description-only updates. If Identity already has this class, do not overwrite it blindly or install a second conflicting class. Confirm its namespace/API and field-update policy, then explicitly reconcile/update that shared implementation after compatibility review. Do not use a legacy writer that nulls absent role/model fields.
4. Identity Composer must map `Database\\Seeders\\` to `database/seeders/`. Refresh its existing Composer autoload metadata. Register the service seeder exactly once in the existing DatabaseSeeder; do not replace that file:

```php
\$this->call([
    \\Database\\Seeders\\Permissions\\{$class}::class,
]);
```

5. Through Identity's existing reviewed Artisan workflow, run the registered seeder, or target `php artisan db:seed --class='Database\Seeders\Permissions\{$class}'` against the verified intended database. Never run it inside the source microservice. Generation does not execute seeders or access a database.
6. Verify one record for each module/subject/action identity; compare `permissions-{$service}-keys.json` with MainGate's exact action-subject middleware keys. Validate all three translated JSON descriptions and that existing IDs, role references, grants, model/active flags and unrelated records remain intact. Repeat the seeder and verify no changes.
7. Refresh permission caches only through Identity/MainGate's existing supported mechanism; none was established by the supplied reference. Test allowed and denied gateway requests in the real applications.
8. Regenerate in the source service using dry-run/check and review catalog updates. Never hand-edit generator-owned source output. Copy reviewed service files again; coordinate shared writer updates across services.

## Reviewed write policy and database limits

The writer validates the entire trusted installed PHP catalog before any DB query, then uses a transaction, reads/locks existing permission keys to detect canonical collisions across subjects, checks existing duplicates/canonical collisions and inserts only missing identities. Existing records update **description only** when changed. IDs are DB-generated. No roles, grants, optional operational fields or unrelated permissions are written/deleted. Role/model/active declarations and other unexpected fields are rejected rather than guessed; preserve those through Identity's separate operational workflows.

The reference does not supply the complete Identity schema or a concurrency/unique-index contract. Run seeders exclusively (no concurrent permission writers) through the existing maintenance workflow; row locks do not prevent races inserting a previously absent identity without a verified unique constraint. Review module/subject/action uniqueness and required insert defaults in Identity; no migrations or IDs are generated. Existing ambiguous records fail and roll back rather than merging grants. A trusted PHP catalog is executable configuration: never pass request-controlled paths or install unreviewed files.

No roles are created, attached, inferred or granted. No user permissions, migrations, cache APIs, cross-repository changes or deployment are generated. MainGate and Service Gateway generation remain unchanged. Actual Identity runtime/schema/cache integration remains unverified.
MD
            ."\n";
    }
}
