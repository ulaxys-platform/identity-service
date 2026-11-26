<?php

namespace Ulaxys\Identity\Integration;

use Illuminate\Filesystem\Filesystem;

/** A whole-bundle staged replacement; validation/comparison happen before publication. */
final class OutputWriter
{
    public function __construct(private Filesystem $filesystem) {}

    private const OWNER = 'ulaxys-integration/v1';

    private const LEDGER = '.ulaxys-generated.json';

    public function apply(array $files, string $destination, bool $dryRun, bool $check): array
    {
        IntegrationMetadata::relativePath($destination);
        $root = base_path('generated/integration');
        $target = $root.'/'.$destination;
        $this->assertAncestors($target);
        for ($ancestor = dirname($target); $ancestor !== $root && str_starts_with($ancestor, $root.'/'); $ancestor = dirname($ancestor)) {
            if (is_file($ancestor.'/'.self::LEDGER)) {
                throw new IntegrationException('Nested generated bundles are not supported.');
            }
        }
        $encoded = $groups = [];
        foreach ($files as $path => $content) {
            IntegrationMetadata::relativePath($path);
            if ($content instanceof NativeMainGateFile || $content instanceof NativeIdentityFile || $content instanceof NativeDeployFile) {
                if ($content->path !== $path) {
                    throw new IntegrationException('Native file identity mismatch.');
                }
                $groups[$path] = match (true) {
                    $content instanceof NativeMainGateFile => 'gateway',
                    $content instanceof NativeIdentityFile => 'permissions',
                    default => 'deploy',
                };
                $encoded[$path] = $content->contents;

                continue;
            }
            if (! str_ends_with($path, '.json') || ! is_array($content)) {
                throw new IntegrationException('Only JSON declarations or reviewed native file objects may be published.');
            }
            $groups[$path] = str_starts_with($content['kind'] ?? '', 'gateway-') ? 'gateway'
                : (str_starts_with($content['kind'] ?? '', 'deploy-') ? 'deploy' : 'permissions');
            $encoded[$path] = json_encode(['generatedBy' => self::OWNER, ...$content], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        }
        foreach (array_keys($encoded) as $left) {
            foreach (array_keys($encoded) as $right) {
                if (str_starts_with($right, $left.'/')) {
                    throw new IntegrationException('Output file/directory collision.');
                }
            }
        }
        ksort($encoded);
        $plan = $this->compare($target, $encoded, $groups);
        if ($dryRun || $check || ! array_filter($plan['changes'], fn ($status) => $status !== 'unchanged')) {
            return $plan['changes'];
        }
        $fs = $this->filesystem;
        if (! is_dir($root) && ! $fs->makeDirectory($root, 0755, true)) {
            throw new IntegrationException('Could not create the allowed output root.');
        }
        $lockPath = $root.'/.ulaxys.lock';
        $this->assertAncestors($lockPath);
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new IntegrationException('Could not open generator lock.');
        }
        $stage = $backup = null;
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new IntegrationException('Could not acquire generator lock.');
            }
            if (glob($root.'/.backup-*') || glob($root.'/.stage-*')) {
                throw new IntegrationException('An interrupted output transaction remains. Inspect .backup/.stage directories and recover before retrying.');
            }
            $this->assertAncestors($target);
            $plan = $this->compare($target, $encoded, $groups); // Recheck ownership and edits under lock.
            $token = bin2hex(random_bytes(8));
            $stage = $root.'/.stage-'.$token;
            $backup = $root.'/.backup-'.$token;
            if (! $fs->makeDirectory($stage, 0700)) {
                throw new IntegrationException('Could not stage output bundle.');
            }
            if (is_dir($target) && ! $fs->copyDirectory($target, $stage)) {
                throw new IntegrationException('Could not preserve existing output bundle.');
            }
            foreach ($encoded as $path => $bytes) {
                $fs->ensureDirectoryExists(dirname($stage.'/'.$path));
                if ($fs->put($stage.'/'.$path, $bytes) !== strlen($bytes)) {
                    throw new IntegrationException('Could not stage a complete generated file.');
                }
            }
            $hashes = $plan['hashes'];
            foreach ($plan['removed'] as $path) {
                if (! $fs->delete($stage.'/'.$path)) {
                    throw new IntegrationException('Could not remove obsolete generated file from staging.');
                }
                unset($hashes[$path]);
            }
            foreach ($encoded as $path => $bytes) {
                $hashes[$path] = ['sha256' => hash('sha256', $bytes), 'group' => $groups[$path]];
            }
            ksort($hashes);
            $ledger = json_encode(['generatedBy' => self::OWNER, 'files' => $hashes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            if ($fs->put($stage.'/'.self::LEDGER, $ledger) !== strlen($ledger)) {
                throw new IntegrationException('Could not stage ownership ledger.');
            }
            $fs->ensureDirectoryExists(dirname($target));
            if (file_exists($target) && ! $fs->move($target, $backup)) {
                throw new IntegrationException('Could not prepare atomic bundle replacement.');
            }
            if (! $fs->move($stage, $target)) {
                if (is_dir($backup) && ! @rename($backup, $target)) {
                    throw new IntegrationException('Bundle publication and rollback failed; recover the .backup directory before retrying.');
                }
                throw new IntegrationException('Bundle publication failed; previous output restored.');
            }
            $stage = null;
            if (is_dir($backup) && ! $fs->deleteDirectory($backup)) {
                throw new IntegrationException('Output published but backup cleanup failed; inspect the output root.');
            }
            $backup = null;

            return $plan['changes'];
        } catch (\Throwable $error) {
            if ($backup !== null && is_dir($backup) && ! file_exists($target)) {
                if (! @rename($backup, $target)) {
                    throw new IntegrationException('Output rollback failed; recover the retained .backup directory before retrying.', 0, $error);
                }
                $backup = null;
            }
            if ($error instanceof IntegrationException) {
                throw $error;
            }
            throw new IntegrationException('Output transaction failed; inspect filesystem permissions/free space and retained backups before retrying.', 0, $error);
        } finally {
            if ($stage !== null && is_dir($stage)) {
                $fs->deleteDirectory($stage);
            }
            // A failed rollback retains its backup for explicit recovery.
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function compare(string $target, array $encoded, array $groups): array
    {
        $this->assertTree($target);
        $hashes = [];
        if (is_dir($target) && is_file($target.'/'.self::LEDGER)) {
            try {
                $ledger = json_decode(file_get_contents($target.'/'.self::LEDGER), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new IntegrationException('Invalid generator ownership ledger; refusing overwrite.');
            }
            if (($ledger['generatedBy'] ?? null) !== self::OWNER || ! is_array($ledger['files'] ?? null)) {
                throw new IntegrationException('Unrecognized generator ownership ledger.');
            }
            $hashes = $ledger['files'];
            foreach ($hashes as $path => $record) {
                IntegrationMetadata::relativePath($path);
                if (! is_array($record) || ! is_string($record['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $record['sha256']) || ! in_array($record['group'] ?? null, ['gateway', 'permissions', 'deploy'], true)) {
                    throw new IntegrationException('Invalid generator ownership hash.');
                }
                if (file_exists($target.'/'.$path) && (! is_file($target.'/'.$path) || hash_file('sha256', $target.'/'.$path) !== $record['sha256'])) {
                    throw new IntegrationException('Generated file was edited manually; refusing overwrite: '.$path);
                }
            }
        }
        $changes = [];
        foreach ($encoded as $path => $bytes) {
            $this->assertAncestors($target.'/'.$path);
            if (file_exists($target.'/'.$path) && ! isset($hashes[$path])) {
                throw new IntegrationException('Destination is not generator-owned; refusing overwrite: '.$path);
            }
            $changes[$path] = ! file_exists($target.'/'.$path) ? 'missing' : (hash_file('sha256', $target.'/'.$path) === hash('sha256', $bytes) ? 'unchanged' : 'stale');
        }

        $removed = [];
        foreach ($hashes as $path => $record) {
            if (in_array($record['group'], $groups, true) && ! array_key_exists($path, $encoded)) {
                $changes[$path] = 'obsolete';
                if (is_file($target.'/'.$path)) {
                    $removed[] = $path;
                } else {
                    unset($hashes[$path]);
                }
            }
        }

        return ['changes' => $changes, 'hashes' => $hashes, 'removed' => $removed];
    }

    private function assertAncestors(string $path): void
    {
        for ($current = $path; $current !== dirname($current); $current = dirname($current)) {
            if (is_link($current)) {
                throw new IntegrationException('Symlink output paths are forbidden.');
            }
            if (file_exists($current) && $current !== $path && ! is_dir($current)) {
                throw new IntegrationException('Output ancestor is not a directory.');
            }
        }
        if (file_exists($path) && ! is_dir($path) && ! is_file($path)) {
            throw new IntegrationException('Unsupported output filesystem entry.');
        }
    }

    private function assertTree(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }
        if (! is_dir($path)) {
            throw new IntegrationException('Output bundle destination must be a directory.');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->getFilename() === self::LEDGER && $entry->getPath() !== $path) {
                throw new IntegrationException('Nested generated bundles are not supported.');
            }
            if ($entry->isLink() || (! $entry->isDir() && ! $entry->isFile())) {
                throw new IntegrationException('Symlinks and special files in output bundles are forbidden.');
            }
        }
    }
}
