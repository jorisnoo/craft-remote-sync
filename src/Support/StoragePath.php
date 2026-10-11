<?php

namespace Noo\CraftRemoteSync\Support;

class StoragePath
{
    /** The storage-relative database backup directory, excluded from file syncs. */
    public const BACKUPS_DIR = 'backups';

    /**
     * Validate a storage-relative sync path. Returns an error message or
     * null when the path is safe. The allowlist keeps paths contained in
     * storage/ and free of shell metacharacters, since they end up inside
     * remote rsync specs.
     */
    public static function validate(string $path): ?string
    {
        if (str_contains($path, '..')) {
            return "Invalid path '{$path}': must stay within the storage directory.";
        }

        if (! preg_match('#^[A-Za-z0-9](?:[A-Za-z0-9._-]|/(?!/))*$#', $path)) {
            return "Invalid path '{$path}': only letters, numbers, dots, dashes, underscores and single slashes are allowed.";
        }

        // storage/backups holds the database snapshots and kept pre-pull/pre-push
        // backups on both sides; syncing it (especially with --delete) would
        // copy dumps around or remove local backups.
        $first = strtolower(explode('/', $path, 2)[0]);

        if ($first === self::BACKUPS_DIR) {
            return "Invalid path '{$path}': storage/".self::BACKUPS_DIR.' holds database backups and cannot be synced.';
        }

        return null;
    }

    public static function normalize(string $path): string
    {
        return trim($path, '/');
    }
}
