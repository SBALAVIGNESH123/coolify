<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\PgbackrestRepo;
use App\Models\ScheduledDatabaseBackup;
use App\Models\StandalonePostgresql;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

class PgBackrestService
{
    private const PGDATA_PATH = '/var/lib/postgresql/data';
    private const CONFIG_PATH_CONTAINER = '/etc/pgbackrest/pgbackrest.conf';

    // Enterprise Defaults
    private const DEFAULT_COMPRESS_TYPE = 'zstd';
    private const DEFAULT_COMPRESS_LEVEL = 3;
    private const DEFAULT_PROCESS_MAX = 2;
    private const DEFAULT_LOG_LEVEL = 'info';

    public static function getStanzaName(StandalonePostgresql $database): string
    {
        return $database->uuid;
    }

    public static function generateConfig(StandalonePostgresql $database): ?string
    {
        $backup = $database->pgbackrestBackups()->where('enabled', true)->first();
        if (!$backup)
            return null;

        $repos = $backup->enabledPgbackrestRepos()->get();
        if ($repos->isEmpty())
            return null;

        $stanza = self::getStanzaName($database);

        $processMax = $backup->pgbackrest_process_max ?? self::DEFAULT_PROCESS_MAX;
        $compressType = $backup->pgbackrest_compress_type ?? self::DEFAULT_COMPRESS_TYPE;
        $compressLevel = $backup->pgbackrest_compress_level ?? self::DEFAULT_COMPRESS_LEVEL;

        $config = "[global]\n";
        $config .= "repo1-retention-full=2\n";
        $config .= "process-max={$processMax}\n";
        $config .= "compress-type={$compressType}\n";
        $config .= "compress-level={$compressLevel}\n";
        $config .= "log-level-console=" . self::DEFAULT_LOG_LEVEL . "\n";
        $config .= "log-level-file=off\n";
        $config .= "start-fast=y\n";
        $config .= "delta=y\n";

        if ($backup->pgbackrest_archive_mode === 'minimal') {
            $config .= "archive-check=n\n";
        }

        $config .= "\n[{$stanza}]\n";
        $config .= "pg1-path=" . self::PGDATA_PATH . "\n";

        $validRepos = 0;
        foreach ($repos as $repo) {
            $conf = self::generateRepoConfig($repo, $database);
            if ($conf) {
                $config .= $conf;
                $validRepos++;
            }
        }

        return $validRepos > 0 ? $config : null;
    }

    private static function generateRepoConfig(PgbackrestRepo $repo, StandalonePostgresql $database): string
    {
        $key = $repo->getRepoKey();

        if ($repo->isS3()) {
            $s3 = $repo->s3Storage;
            if (!$s3)
                return '';

            // Strict Validation prevents injection
            $bucket = self::sanitize($s3->bucket);
            $endpoint = self::cleanEndpoint($s3->endpoint);
            $region = self::sanitize($s3->region ?: 'us-east-1');

            $conf = "{$key}-type=s3\n";
            $conf .= "{$key}-path=/{$database->uuid}\n";
            $conf .= "{$key}-s3-bucket={$bucket}\n";
            $conf .= "{$key}-s3-endpoint={$endpoint}\n";
            $conf .= "{$key}-s3-region={$region}\n";
            $conf .= "{$key}-s3-uri-style=path\n";

            $retentionType = $repo->retention_full_type === 'time' ? 'time' : 'count';
            $retentionVal = (int) ($repo->retention_full ?: 2);
            $conf .= "{$key}-retention-full-type={$retentionType}\n";
            $conf .= "{$key}-retention-full={$retentionVal}\n";

            return $conf;
        }

        return "{$key}-type=posix\n{$key}-path={$repo->getEffectivePath()}\n";
    }

    public static function buildBackupCommand(
        string $stanza,
        ScheduledDatabaseBackup $backup,
        string $network,
        string $containerName
    ): string {
        $cmd = "pgbackrest --stanza={$stanza} --type=full --resume backup";
        return self::wrapDocker($cmd, $backup, $network, $containerName);
    }

    public static function buildRestoreCommand(
        string $stanza,
        ScheduledDatabaseBackup $backup,
        string $network,
        string $targetContainer,
        ?string $targetTime = null
    ): string {
        $cmd = "pgbackrest --stanza={$stanza} --delta restore";

        if ($targetTime) {
            // PITR: Point-in-Time Recovery
            // Validating timestamp format is tricky, assuming trusted input or basic check
            $cmd .= " --type=time --target=" . escapeshellarg($targetTime) . " --target-action=promote";
        }

        return self::wrapDocker($cmd, $backup, $network, $targetContainer);
    }

    public static function buildInfoCommand(
        string $stanza,
        ScheduledDatabaseBackup $backup,
        string $network,
        string $containerName
    ): string {
        $cmd = "pgbackrest --stanza={$stanza} --output=json info";
        return self::wrapDocker($cmd, $backup, $network, $containerName);
    }

    public static function buildStanzaCreateCommand(
        string $stanza,
        ScheduledDatabaseBackup $backup,
        string $network,
        string $containerName
    ): string {
        $cmd = "pgbackrest --stanza={$stanza} stanza-create";
        return self::wrapDocker($cmd, $backup, $network, $containerName);
    }

    public static function buildExpireCommand(
        string $stanza,
        ScheduledDatabaseBackup $backup,
        string $network,
        string $containerName
    ): string {
        $cmd = "pgbackrest --stanza={$stanza} expire";
        return self::wrapDocker($cmd, $backup, $network, $containerName);
    }

    private static function wrapDocker(
        string $cmd,
        ScheduledDatabaseBackup $backup,
        string $network,
        string $containerName
    ): string {
        $image = config('coolify.pgbackrest_image', 'pgbackrest/pgbackrest:latest');
        $env = self::buildEnvVars($backup);
        $dockerEnv = self::formatDockerEnv($env);

        $configHost = "/tmp/pgbackrest-{$backup->uuid}.conf";
        $mounts = "-v {$configHost}:" . self::CONFIG_PATH_CONTAINER . ":ro";
        $mounts .= " --volumes-from {$containerName}:ro";

        return "docker run --rm --network {$network} {$dockerEnv} {$mounts} {$image} {$cmd}";
    }

    private static function buildEnvVars(ScheduledDatabaseBackup $backup): array
    {
        $vars = [];
        $repos = $backup->enabledPgbackrestRepos()->get();

        foreach ($repos as $repo) {
            if ($repo->isS3() && $repo->s3Storage) {
                $s3 = $repo->s3Storage;
                $idx = $repo->repo_number;
                $vars["PGBACKREST_REPO{$idx}_S3_KEY"] = $s3->key;
                $vars["PGBACKREST_REPO{$idx}_S3_KEY_SECRET"] = $s3->secret;

                if ($repo->encryption_key) {
                    $vars["PGBACKREST_REPO{$idx}_CIPHER_TYPE"] = 'aes-256-cbc';
                    $vars["PGBACKREST_REPO{$idx}_CIPHER_PASS"] = $repo->encryption_key;
                }
            }
        }
        return $vars;
    }

    private static function formatDockerEnv(array $vars): string
    {
        return collect($vars)->map(function ($val, $key) {
            if (!preg_match('/^[A-Z0-9_]+$/', $key))
                throw new RuntimeException("Invalid Env Key: $key");
            return "-e " . escapeshellarg("{$key}={$val}");
        })->implode(' ');
    }

    private static function cleanEndpoint(string $endpoint): string
    {
        $endpoint = preg_replace('#^https?://#', '', $endpoint);
        return rtrim($endpoint, '/');
    }

    private static function sanitize(string $value): string
    {
        if (preg_match('/[^a-zA-Z0-9\-\._@]/', $value))
            throw new InvalidArgumentException("Invalid config value: {$value}");
        return $value;
    }

    // Helper Methods for JSON Parsing

    public static function parseInfoJson(string $json): ?array
    {
        $data = json_decode($json, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $data : null;
    }

    public static function getLatestBackup(array $info): ?array
    {
        $backups = $info[0]['backup'] ?? [];
        return empty($backups) ? null : end($backups);
    }

    public static function restoreWithDowntime(
        ScheduledDatabaseBackup $backup,
        ?string $targetTime = null,
        int $timeout = 3600
    ): void {
        $database = $backup->database;
        if (!$database) {
            throw new RuntimeException("Database not found for backup: {$backup->id}");
        }

        $server = $database->destination->server;
        if (!$server) {
            throw new RuntimeException("Server not found for database: {$database->uuid}");
        }

        $stanza = self::getStanzaName($database);
        PgBackrestValidator::validateStanza($stanza);

        // Validate PITR timestamp if provided
        if ($targetTime && !strtotime($targetTime)) {
            throw new InvalidArgumentException("Invalid target time for PITR: {$targetTime}");
        }

        try {
            // Stop database to ensure consistent state
            if (method_exists($database, 'stop')) {
                $database->stop();
            } else {
                instant_remote_process(["docker stop -t 30 {$database->uuid}"], $server);
            }

            // Restore data using pgbackrest delta restore
            $network = $database->destination->network;
            $restoreCmd = self::buildRestoreCommand($stanza, $backup, $network, $database->uuid, $targetTime);

            // Execute restore with extended timeout
            instant_remote_process([$restoreCmd], $server, true, false, $timeout);

            // Restart database
            if (method_exists($database, 'start')) {
                $database->start();
            } else {
                instant_remote_process(["docker start {$database->uuid}"], $server);
            }

        } catch (\Throwable $e) {
            // Attempt to restart database if restore failed
            try {
                instant_remote_process(["docker start {$database->uuid}"], $server);
            } catch (\Throwable $nested) {
                // Ignore nested error, original error is more important
            }
            throw new RuntimeException("Restore failed: " . $e->getMessage(), 0, $e);
        }
    }
}
