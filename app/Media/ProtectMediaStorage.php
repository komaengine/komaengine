<?php

namespace App\Media;

use FilesystemIterator;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

class ProtectMediaStorage
{
    public function migrate(): int
    {
        $source = (string) config('installer.legacy_public_media_path', storage_path('app/public/media'));
        $target = (string) config('filesystems.disks.media.root', storage_path('app/media'));

        $this->assertSafeRoot($source, storage_path('app/public'), 'legacy media');
        $this->assertSafeRoot($target, storage_path(), 'protected media');
        $normalizedTarget = rtrim(str_replace('\\', '/', $target), '/');
        $publicStorage = rtrim(str_replace('\\', '/', storage_path('app/public')), '/');
        if ($normalizedTarget === $publicStorage || str_starts_with($normalizedTarget, $publicStorage.'/')) {
            throw new RuntimeException('Configured protected media root is publicly reachable.');
        }

        if (is_link($source) || is_link($target)) {
            throw new RuntimeException('Media migration refuses symbolic-link roots.');
        }

        File::ensureDirectoryExists($target, 0700, true);
        if (! is_dir($source)) {
            return 0;
        }

        $migrated = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->isLink() || ! $entry->isFile()) {
                throw new RuntimeException('Legacy media contains an unsupported symbolic link or special file.');
            }

            $path = $entry->getPathname();
            $relative = substr($path, strlen($source) + 1);
            if ($relative === false || $relative === '' || str_contains($relative, "\0")) {
                throw new RuntimeException('Legacy media contains an unsafe path.');
            }

            $destination = $target.DIRECTORY_SEPARATOR.$relative;
            $this->assertNoSymlinkSegments(dirname($destination), $target);
            File::ensureDirectoryExists(dirname($destination), 0700, true);

            if (is_link($destination) || (file_exists($destination) && ! is_file($destination))) {
                throw new RuntimeException("Protected media path is occupied: {$relative}");
            }
            if (is_file($destination)) {
                if (filesize($path) !== filesize($destination)
                    || ! hash_equals(hash_file('sha256', $path), hash_file('sha256', $destination))) {
                    throw new RuntimeException("Protected media conflicts with legacy media: {$relative}");
                }

                continue;
            }

            $temporary = $destination.'.tmp-'.bin2hex(random_bytes(8));
            try {
                if (! copy($path, $temporary)
                    || filesize($path) !== filesize($temporary)
                    || ! hash_equals(hash_file('sha256', $path), hash_file('sha256', $temporary))
                    || ! @chmod($temporary, 0600)
                    || ! @rename($temporary, $destination)) {
                    throw new RuntimeException("Legacy media could not be copied safely: {$relative}");
                }
            } finally {
                if (is_file($temporary)) {
                    File::delete($temporary);
                }
            }

            $migrated++;
        }

        if (! File::deleteDirectory($source)) {
            throw new RuntimeException('Legacy public media was copied but could not be removed from the public path.');
        }

        return $migrated;
    }

    private function assertSafeRoot(string $path, string $parent, string $label): void
    {
        $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');
        $normalizedParent = rtrim(str_replace('\\', '/', $parent), '/');

        if ($normalizedPath === '' || $normalizedPath === $normalizedParent
            || ! str_starts_with($normalizedPath, $normalizedParent.'/')) {
            throw new RuntimeException("Configured {$label} root is unsafe.");
        }
    }

    private function assertNoSymlinkSegments(string $path, string $root): void
    {
        $relative = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
        $cursor = $root;
        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
            if ($segment === '') {
                continue;
            }
            $cursor .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($cursor)) {
                throw new RuntimeException('Protected media contains a symbolic-link path.');
            }
        }
    }
}
