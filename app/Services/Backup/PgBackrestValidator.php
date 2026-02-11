<?php

declare(strict_types=1);

namespace App\Services\Backup;

use InvalidArgumentException;

class PgBackrestValidator
{
    // Regex for safe Stanza names (alphanumeric, hyphens, underscores)
    private const PATTERN_STANZA = '/^[a-zA-Z0-9\-_]+$/';

    // Regex for S3 Buckets (generic safe pattern, avoiding strict AWS DNS rules for compatibility with MinIO/others)
    private const PATTERN_BUCKET = '/^[a-z0-9][a-z0-9\.\-]{1,61}[a-z0-9]$/';

    // Allow simple hostnames and IPs, optionally with ports
    private const PATTERN_ENDPOINT = '/^([a-zA-Z0-9\.\-]+)(?::\d+)?$/';

    public static function validateStanza(string $stanza): void
    {
        if (!preg_match(self::PATTERN_STANZA, $stanza)) {
            throw new InvalidArgumentException("Invalid Stanza name: '{$stanza}'. Only alphanumeric characters, hyphens, and underscores are allowed.");
        }
    }

    public static function validateS3Config(string $bucket, string $endpoint, string $region): void
    {
        if (!preg_match(self::PATTERN_BUCKET, $bucket)) {
            // We log a warning but don't strictly block if it looks vaguely sane, 
            // but for "Enterprise" grade we should probably be strict.
            // Let's enforce the regex.
            throw new InvalidArgumentException("Invalid S3 Bucket name: '{$bucket}'. Must be lowercase alphanumeric, hyphens, or dots.");
        }

        // Clean endpoint protocol
        $cleanEndpoint = preg_replace('#^https?://#', '', $endpoint);
        if (!preg_match(self::PATTERN_ENDPOINT, $cleanEndpoint)) {
            throw new InvalidArgumentException("Invalid S3 Endpoint: '{$endpoint}'. Should be a valid hostname or IP, without protocol.");
        }

        if (!preg_match('/^[a-z0-9\-]+$/', $region)) {
            throw new InvalidArgumentException("Invalid S3 Region: '{$region}'.");
        }
    }

    public static function validateProcessMax(int $value): void
    {
        if ($value < 1 || $value > 32) {
            throw new InvalidArgumentException("Process Max must be between 1 and 32. Received: {$value}");
        }
    }

    public static function validateCompressionLevel(int $value): void
    {
        if ($value < 0 || $value > 22) { // Zstd max is technically higher but 22 is practical max
            throw new InvalidArgumentException("Compression Level must be between 0 and 22. Received: {$value}");
        }
    }
}
