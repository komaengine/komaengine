<?php

use App\Archives\ArchiveTempCleaner;
use App\Models\SeriesArchiveImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Create a staging workspace whose every entry was last touched $ageDays ago.
 *
 * @param  array<string, string>  $files
 */
function stagedArchiveWorkspace(string $root, int $ageDays, ?string $name = null, array $files = ['media-00000001' => 'staged']): string
{
    $path = storage_path('app/'.$root.'/'.($name ?? (string) Str::uuid()));
    $time = time() - ($ageDays * 86400);

    foreach ($files as $file => $bytes) {
        File::ensureDirectoryExists(dirname($path.'/'.$file));
        File::put($path.'/'.$file, $bytes);
        touch($path.'/'.$file, $time);
    }

    File::ensureDirectoryExists($path);

    foreach (File::directories($path) as $directory) {
        touch($directory, $time);
    }

    touch($path, $time);

    return $path;
}

/** @return array{status:int,actions:array<string,string>,reasons:array<string,string>,applied:bool} */
function runArchiveTempCleanup(bool $force): array
{
    $status = Artisan::call('koma:archive-temp:clean', ['--json' => true, '--force' => $force]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $workspaces = collect($report['workspaces']);

    return [
        'status' => $status,
        'actions' => $workspaces->pluck('action', 'path')->all(),
        'reasons' => $workspaces->pluck('reason', 'path')->all(),
        'applied' => $report['applied'],
    ];
}

beforeEach(function () {
    // Point storage at a sandbox so this never inspects real staging roots.
    $this->originalStorage = storage_path();
    $this->sandbox = storage_path('framework/testing/archive-temp-'.Str::uuid());
    File::ensureDirectoryExists($this->sandbox.'/storage/app');
    File::ensureDirectoryExists($this->sandbox.'/outside');
    $this->app->useStoragePath($this->sandbox.'/storage');
});

afterEach(function () {
    $this->app->useStoragePath($this->originalStorage);
    File::deleteDirectory($this->sandbox);
});

test('cleanup previews by default and changes nothing', function () {
    $staleImport = stagedArchiveWorkspace('archive-import-temp', 10);
    $staleExport = stagedArchiveWorkspace('archive-temp', 10);
    $recent = stagedArchiveWorkspace('archive-import-temp', 2);

    $report = runArchiveTempCleanup(force: false);

    expect($report['status'])->toBe(0)
        ->and($report['applied'])->toBeFalse()
        ->and($report['actions'])->toEqual([
            $staleImport => 'remove',
            $staleExport => 'remove',
            $recent => 'skip',
        ])
        ->and($report['reasons'][$recent])->toBe('modified within the retention window')
        ->and(is_dir($staleImport) && is_dir($staleExport) && is_dir($recent))->toBeTrue();

    $this->artisan('koma:archive-temp:clean')
        ->expectsOutputToContain('Nothing was changed. Re-run with --force to delete 2 stale workspace(s).')
        ->assertSuccessful();

    expect(is_dir($staleImport) && is_dir($staleExport))->toBeTrue();
});

test('force removes only stale staging workspaces', function () {
    $staleImport = stagedArchiveWorkspace('archive-import-temp', 10, files: [
        'media-00000001' => 'one',
        'media-00000002' => 'two',
    ]);
    $staleExport = stagedArchiveWorkspace('archive-temp', 30);
    $recent = stagedArchiveWorkspace('archive-import-temp', 6);
    $stillWritten = stagedArchiveWorkspace('archive-temp', 10);
    File::put($stillWritten.'/00000002.media', 'written a moment ago');
    touch($stillWritten, time() - (10 * 86400));
    $foreign = stagedArchiveWorkspace('archive-import-temp', 10, 'operator-notes');
    $strayFile = storage_path('app/archive-import-temp/'.Str::uuid());
    File::put($strayFile, 'not a directory');
    touch($strayFile, time() - (10 * 86400));
    $retainedSource = storage_path('app/private/archive-imports/source/'.Str::uuid().'.koma.zip');
    File::ensureDirectoryExists(dirname($retainedSource));
    File::put($retainedSource, 'retained upload');
    touch($retainedSource, time() - (400 * 86400));

    $report = runArchiveTempCleanup(force: true);

    expect($report['status'])->toBe(0)
        ->and($report['applied'])->toBeTrue()
        ->and($report['actions'])->toEqual([
            $staleImport => 'removed',
            $staleExport => 'removed',
            $recent => 'skip',
            $stillWritten => 'skip',
            $foreign => 'skip',
            $strayFile => 'skip',
        ])
        ->and($report['reasons'][$stillWritten])->toBe('modified within the retention window')
        ->and($report['reasons'][$foreign])->toBe('not named like a staging workspace')
        ->and($report['reasons'][$strayFile])->toBe('not a workspace directory')
        ->and(file_exists($staleImport))->toBeFalse()
        ->and(file_exists($staleExport))->toBeFalse()
        ->and(is_dir($recent) && is_dir($stillWritten) && is_dir($foreign))->toBeTrue()
        ->and(is_file($strayFile))->toBeTrue()
        // Retained uploads are never eligible, whatever their age.
        ->and(File::get($retainedSource))->toBe('retained upload');
});

test('cleanup never follows or removes a symbolic link', function () {
    $precious = $this->sandbox.'/outside/precious.txt';
    File::put($precious, 'outside the staging roots');
    touch($precious, time() - (10 * 86400));
    touch(dirname($precious), time() - (10 * 86400));

    File::ensureDirectoryExists(storage_path('app/archive-import-temp'));
    $linkedWorkspace = storage_path('app/archive-import-temp/'.Str::uuid());
    symlink(dirname($precious), $linkedWorkspace);

    $containsLink = stagedArchiveWorkspace('archive-import-temp', 10);
    symlink($precious, $containsLink.'/media-00000002');
    touch($containsLink, time() - (10 * 86400));

    $report = runArchiveTempCleanup(force: true);

    expect($report['actions'])->toEqual([
        $linkedWorkspace => 'skip',
        $containsLink => 'skip',
    ])
        ->and($report['reasons'][$linkedWorkspace])->toBe('not a workspace directory')
        ->and($report['reasons'][$containsLink])->toBe('contains a symbolic link or special file')
        ->and(is_link($linkedWorkspace))->toBeTrue()
        ->and(is_file($containsLink.'/media-00000001'))->toBeTrue()
        ->and(File::get($precious))->toBe('outside the staging roots');
});

test('a staging root that is itself a link is left alone', function () {
    $elsewhere = $this->sandbox.'/outside/elsewhere';
    $victim = $elsewhere.'/'.Str::uuid();
    File::ensureDirectoryExists($victim);
    File::put($victim.'/media-00000001', 'not ours');
    touch($victim.'/media-00000001', time() - (10 * 86400));
    touch($victim, time() - (10 * 86400));
    symlink($elsewhere, storage_path('app/archive-temp'));

    $report = runArchiveTempCleanup(force: true);

    expect($report['actions'])->toBe([storage_path('app/archive-temp') => 'skip'])
        ->and($report['reasons'][storage_path('app/archive-temp')])
        ->toBe('the staging root is a symbolic link or is not a directory')
        ->and(is_file($victim.'/media-00000001'))->toBeTrue();
});

test('a workspace nested deeper than staging ever writes is left alone', function () {
    $nested = stagedArchiveWorkspace('archive-temp', 10, files: ['a/b/deep.media' => 'unexpected']);
    touch($nested.'/a/b', time() - (10 * 86400));

    $report = runArchiveTempCleanup(force: true);

    expect($report['actions'])->toBe([$nested => 'skip'])
        ->and($report['reasons'][$nested])->toBe('is nested deeper than a staging workspace')
        ->and(is_file($nested.'/a/b/deep.media'))->toBeTrue();
});

test('a running import protects every import workspace but not export residue', function () {
    $import = stagedArchiveWorkspace('archive-import-temp', 10);
    $export = stagedArchiveWorkspace('archive-temp', 10);
    $record = SeriesArchiveImport::create([
        'user_id' => User::factory()->create()->id,
        'source_name' => 'running.koma.zip',
        'status' => SeriesArchiveImport::STATUS_IMPORTING,
        'conflict_policy' => 'fail',
    ]);

    $report = runArchiveTempCleanup(force: true);

    expect($report['actions'])->toEqual([$import => 'skip', $export => 'removed'])
        ->and($report['reasons'][$import])->toBe('an archive import is in progress')
        ->and(is_dir($import))->toBeTrue();

    // A record abandoned by a killed worker is no longer a running import.
    SeriesArchiveImport::query()->whereKey($record->id)->update(['updated_at' => now()->subDays(3)]);

    expect(runArchiveTempCleanup(force: true)['actions'])->toBe([$import => 'removed'])
        ->and(file_exists($import))->toBeFalse();
});

test('import workspaces are left alone when import activity cannot be read', function () {
    $import = stagedArchiveWorkspace('archive-import-temp', 10);
    $export = stagedArchiveWorkspace('archive-temp', 10);
    Schema::drop('series_archive_imports');

    $report = runArchiveTempCleanup(force: true);

    expect($report['actions'])->toEqual([$import => 'skip', $export => 'removed'])
        ->and($report['reasons'][$import])->toBe('import activity could not be determined')
        ->and(is_dir($import))->toBeTrue();
});

test('a workspace that changes after review is not removed', function () {
    $workspace = stagedArchiveWorkspace('archive-temp', 10);
    $cleaner = app(ArchiveTempCleaner::class);
    $plan = $cleaner->plan(7 * 86400);

    expect($plan)->toHaveCount(1)->and($plan[0]['action'])->toBe(ArchiveTempCleaner::REMOVE);

    // Same age, different contents: no longer the directory that was reviewed.
    File::put($workspace.'/00000002.media', 'arrived after review');
    touch($workspace.'/00000002.media', time() - (10 * 86400));
    touch($workspace, time() - (10 * 86400));

    $result = $cleaner->apply($plan, 7 * 86400);

    expect($result[0]['action'])->toBe(ArchiveTempCleaner::SKIP)
        ->and($result[0]['reason'])->toBe('changed since it was reviewed')
        ->and(is_file($workspace.'/media-00000001'))->toBeTrue();
});

test('cleanup refuses a retention window shorter than one day', function (string $days) {
    $workspace = stagedArchiveWorkspace('archive-import-temp', 10);

    expect(Artisan::call('koma:archive-temp:clean', ['--older-than-days' => $days, '--force' => true]))->toBe(2)
        ->and(is_dir($workspace))->toBeTrue();
})->with(['0', '-3', 'soon', '1.5']);

test('the cleaner itself rejects a window shorter than one day', function () {
    $workspace = stagedArchiveWorkspace('archive-import-temp', 10);

    expect(fn () => app(ArchiveTempCleaner::class)->plan(ArchiveTempCleaner::MINIMUM_AGE_SECONDS - 1))
        ->toThrow(InvalidArgumentException::class)
        ->and(is_dir($workspace))->toBeTrue();
});

test('removal refuses a workspace that was swapped for a link after review', function () {
    $workspace = stagedArchiveWorkspace('archive-temp', 10);
    $precious = $this->sandbox.'/outside/precious.txt';
    File::put($precious, 'outside the staging roots');
    $cleaner = app(ArchiveTempCleaner::class);
    $reviewed = lstat($workspace);
    $remove = new ReflectionMethod(ArchiveTempCleaner::class, 'remove');

    // The reviewed directory is replaced by a link to somewhere else.
    File::deleteDirectory($workspace);
    symlink(dirname($precious), $workspace);

    expect(fn () => $remove->invoke($cleaner, $workspace, $reviewed['dev'], $reviewed['ino']))
        ->toThrow(RuntimeException::class, 'changed while it was being removed')
        ->and(File::get($precious))->toBe('outside the staging roots')
        ->and(is_link($workspace))->toBeTrue();

    // A different real directory at the same path is not the reviewed one either.
    unlink($workspace);
    File::ensureDirectoryExists($workspace);
    File::put($workspace.'/media-00000001', 'newer workspace');

    expect(fn () => $remove->invoke($cleaner, $workspace, $reviewed['dev'], $reviewed['ino'] + 1))
        ->toThrow(RuntimeException::class, 'changed while it was being removed')
        ->and(is_file($workspace.'/media-00000001'))->toBeTrue();
});
