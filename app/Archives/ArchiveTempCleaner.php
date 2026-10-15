<?php

namespace App\Archives;

use App\Jobs\ImportSeriesArchive;
use App\Models\SeriesArchiveImport;
use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * Finds and removes archive staging workspaces left behind by a process that
 * was killed before its own cleanup ran.
 *
 * Imports and exports delete their workspace in a finally block, so this only
 * ever has hard-kill residue to deal with. It is deliberately narrow: it looks
 * at two fixed roots, considers only UUID-named directories directly inside
 * them, never follows a symbolic link, and never looks at retained source
 * archives. plan() changes nothing; apply() inspects every workspace again
 * immediately before deleting it and leaves anything that changed.
 */
final class ArchiveTempCleaner
{
    /** Anything younger can still belong to an import or export in progress. */
    public const MINIMUM_AGE_SECONDS = 86400;

    public const REMOVE = 'remove';

    public const SKIP = 'skip';

    public const REMOVED = 'removed';

    public const FAILED = 'failed';

    private const ROOTS = [
        'import' => 'archive-import-temp',
        'export' => 'archive-temp',
    ];

    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    /** Staging workspaces are flat; anything deeper is not one of ours. */
    private const MAXIMUM_DEPTH = 2;

    private const TYPE_MASK = 0170000;

    private const TYPE_DIRECTORY = 0040000;

    private const TYPE_FILE = 0100000;

    /**
     * @return list<array{kind:string,path:string,action:string,reason:string,files:int,bytes:int,modified_at:?int,identity:?string}>
     */
    public function plan(int $olderThanSeconds): array
    {
        if ($olderThanSeconds < self::MINIMUM_AGE_SECONDS) {
            throw new InvalidArgumentException('Archive workspaces newer than one day are never eligible for cleanup.');
        }

        $cutoff = time() - $olderThanSeconds;
        $entries = [];

        foreach (self::ROOTS as $kind => $directory) {
            $root = storage_path('app'.DIRECTORY_SEPARATOR.$directory);

            if ($this->lstat($root) === null) {
                continue;
            }

            $blocked = $this->rootProblem($root);

            if ($blocked !== null) {
                $entries[] = $this->entry($kind, $root, self::SKIP, $blocked);

                continue;
            }

            $names = $this->children($root);

            if ($names === null) {
                $entries[] = $this->entry($kind, $root, self::SKIP, 'the staging root could not be read');

                continue;
            }

            $activity = $kind === 'import' && $names !== [] ? $this->importActivity() : null;

            foreach ($names as $name) {
                $entries[] = $this->inspect($kind, $root, $name, $cutoff, $activity);
            }
        }

        return $entries;
    }

    /**
     * Delete the workspaces a reviewed plan marked for removal.
     *
     * @param  list<array{kind:string,path:string,action:string,reason:string,files:int,bytes:int,modified_at:?int,identity:?string}>  $plan
     * @return list<array{kind:string,path:string,action:string,reason:string,files:int,bytes:int,modified_at:?int,identity:?string}>
     */
    public function apply(array $plan, int $olderThanSeconds): array
    {
        $current = [];

        foreach ($this->plan($olderThanSeconds) as $entry) {
            $current[$entry['path']] = $entry;
        }

        $results = [];

        foreach ($plan as $entry) {
            if ($entry['action'] !== self::REMOVE) {
                $results[] = $entry;

                continue;
            }

            $now = $current[$entry['path']] ?? null;

            if ($now === null || $now['action'] !== self::REMOVE || $now['identity'] !== $entry['identity']) {
                $results[] = array_merge($entry, [
                    'action' => self::SKIP,
                    'reason' => 'changed since it was reviewed',
                ]);

                continue;
            }

            try {
                [$device, $inode] = array_map('intval', explode(':', $entry['identity']));
                $this->remove($entry['path'], $device, $inode);
                $results[] = array_merge($entry, ['action' => self::REMOVED, 'reason' => 'stale workspace removed']);
            } catch (Throwable $exception) {
                report($exception);
                $results[] = array_merge($entry, ['action' => self::FAILED, 'reason' => $exception->getMessage()]);
            }
        }

        return $results;
    }

    /**
     * @return array{kind:string,path:string,action:string,reason:string,files:int,bytes:int,modified_at:?int,identity:?string}
     */
    private function inspect(string $kind, string $root, string $name, int $cutoff, ?string $activity): array
    {
        $path = $root.DIRECTORY_SEPARATOR.$name;
        $stat = $this->lstat($path);
        $type = $stat === null ? null : $stat['mode'] & self::TYPE_MASK;

        if ($type !== self::TYPE_DIRECTORY) {
            return $this->entry($kind, $path, self::SKIP, 'not a workspace directory');
        }

        if (preg_match(self::UUID, $name) !== 1) {
            return $this->entry($kind, $path, self::SKIP, 'not named like a staging workspace');
        }

        if (realpath($path) !== realpath($root).DIRECTORY_SEPARATOR.$name) {
            return $this->entry($kind, $path, self::SKIP, 'resolves outside its staging root');
        }

        $contents = $this->measure($path);

        if ($contents['problem'] !== null) {
            return $this->entry($kind, $path, self::SKIP, $contents['problem'], $contents);
        }

        if ($activity !== null) {
            return $this->entry($kind, $path, self::SKIP, $activity, $contents);
        }

        if ($contents['newest'] > $cutoff) {
            return $this->entry($kind, $path, self::SKIP, 'modified within the retention window', $contents);
        }

        return $this->entry(
            $kind,
            $path,
            self::REMOVE,
            'untouched since '.gmdate('Y-m-d\TH:i:s\Z', $contents['newest']),
            $contents,
            implode(':', [$stat['dev'], $stat['ino'], $contents['newest'], $contents['files'], $contents['bytes']]),
        );
    }

    /**
     * The root must be the real directory this application stages into, not
     * a link to somewhere else that merely has the right name.
     */
    private function rootProblem(string $root): ?string
    {
        $stat = $this->lstat($root);

        if ($stat === null || ($stat['mode'] & self::TYPE_MASK) !== self::TYPE_DIRECTORY) {
            return 'the staging root is a symbolic link or is not a directory';
        }

        $storage = realpath(storage_path('app'));

        if ($storage === false || realpath($root) !== $storage.DIRECTORY_SEPARATOR.basename($root)) {
            return 'the staging root resolves outside application storage';
        }

        return null;
    }

    /**
     * An import that is validating or importing owns a workspace this class
     * cannot identify, so any running import protects every import workspace.
     * If that cannot be determined, nothing is eligible.
     */
    private function importActivity(): ?string
    {
        $timeout = (int) (new ReflectionClass(ImportSeriesArchive::class))->getDefaultProperties()['timeout'];

        try {
            $running = SeriesArchiveImport::query()
                ->whereIn('status', [
                    SeriesArchiveImport::STATUS_VALIDATING,
                    SeriesArchiveImport::STATUS_IMPORTING,
                ])
                ->where('updated_at', '>=', now()->subSeconds($timeout + 60))
                ->exists();
        } catch (Throwable $exception) {
            report($exception);

            return 'import activity could not be determined';
        }

        return $running ? 'an archive import is in progress' : null;
    }

    /**
     * @return array{files:int,bytes:int,newest:int,problem:?string}
     */
    private function measure(string $directory, int $depth = 1): array
    {
        $stat = $this->lstat($directory);
        $names = $this->children($directory);
        $summary = ['files' => 0, 'bytes' => 0, 'newest' => $stat['mtime'] ?? 0, 'problem' => null];

        if ($stat === null || $names === null) {
            return array_merge($summary, ['problem' => 'could not be read']);
        }

        if ($depth > self::MAXIMUM_DEPTH) {
            return array_merge($summary, ['problem' => 'is nested deeper than a staging workspace']);
        }

        foreach ($names as $name) {
            $path = $directory.DIRECTORY_SEPARATOR.$name;
            $entry = $this->lstat($path);
            $type = $entry === null ? null : $entry['mode'] & self::TYPE_MASK;

            if ($type === self::TYPE_FILE) {
                $summary['files']++;
                $summary['bytes'] += $entry['size'];
                $summary['newest'] = max($summary['newest'], $entry['mtime']);

                continue;
            }

            if ($type !== self::TYPE_DIRECTORY) {
                return array_merge($summary, ['problem' => 'contains a symbolic link or special file']);
            }

            $nested = $this->measure($path, $depth + 1);
            $summary['files'] += $nested['files'];
            $summary['bytes'] += $nested['bytes'];
            $summary['newest'] = max($summary['newest'], $nested['newest']);

            if ($nested['problem'] !== null) {
                return array_merge($summary, ['problem' => $nested['problem']]);
            }
        }

        return $summary;
    }

    /**
     * Remove one inspected workspace without ever following a link.
     *
     * Each directory is checked again at the moment it is opened: it must
     * still be a real directory, and the workspace itself must still be the
     * one that was reviewed. A path swapped for a link after inspection is
     * refused rather than followed.
     */
    private function remove(string $directory, ?int $device = null, ?int $inode = null): void
    {
        $stat = $this->lstat($directory);

        if ($stat === null || ($stat['mode'] & self::TYPE_MASK) !== self::TYPE_DIRECTORY
            || ($device !== null && ($stat['dev'] !== $device || $stat['ino'] !== $inode))) {
            throw new RuntimeException('The workspace changed while it was being removed.');
        }

        foreach ($this->children($directory) ?? throw new RuntimeException('The workspace could not be read.') as $name) {
            $path = $directory.DIRECTORY_SEPARATOR.$name;
            $entry = $this->lstat($path);
            $type = $entry === null ? null : $entry['mode'] & self::TYPE_MASK;

            if ($type === self::TYPE_DIRECTORY) {
                $this->remove($path);
            } elseif ($type !== self::TYPE_FILE || ! unlink($path)) {
                throw new RuntimeException('The workspace changed while it was being removed.');
            }
        }

        if (! rmdir($directory)) {
            throw new RuntimeException('The emptied workspace could not be removed.');
        }
    }

    /**
     * @return null|list<string>
     */
    private function children(string $directory): ?array
    {
        try {
            $names = scandir($directory);
        } catch (Throwable) {
            return null;
        }

        return $names === false ? null : array_values(array_diff($names, ['.', '..']));
    }

    /**
     * @return null|array<string, int>
     */
    private function lstat(string $path): ?array
    {
        try {
            $stat = lstat($path);
        } catch (Throwable) {
            return null;
        }

        return $stat === false ? null : $stat;
    }

    /**
     * @param  null|array{files:int,bytes:int,newest:int,problem:?string}  $contents
     * @return array{kind:string,path:string,action:string,reason:string,files:int,bytes:int,modified_at:?int,identity:?string}
     */
    private function entry(
        string $kind,
        string $path,
        string $action,
        string $reason,
        ?array $contents = null,
        ?string $identity = null,
    ): array {
        return [
            'kind' => $kind,
            'path' => $path,
            'action' => $action,
            'reason' => $reason,
            'files' => $contents['files'] ?? 0,
            'bytes' => $contents['bytes'] ?? 0,
            'modified_at' => $contents['newest'] ?? null,
            'identity' => $identity,
        ];
    }
}
