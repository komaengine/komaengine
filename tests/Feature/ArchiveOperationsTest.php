<?php

use App\Jobs\ImportSeriesArchive;
use App\Livewire\Admin\ArchiveImporter;
use App\Models\SeriesArchiveImport;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->editor = User::factory()->create();
    $this->editor->assignRole('Editor');
    Storage::fake('local');
});

test('archive jobs use a dedicated queue and an import scoped overlap lock', function () {
    $job = new ImportSeriesArchive('import-123');
    $middleware = $job->middleware();

    expect($job->queue)->toBe('archive-imports')
        ->and($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and(config('queue.connections.database.retry_after'))->toBeGreaterThan($job->timeout);
});

test('local development worker consumes archive imports before the default queue', function () {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $devCommand = implode(' ', $composer['scripts']['dev']);

    expect($devCommand)->toContain('queue:listen --queue=archive-imports,default --tries=1');
});

test('an editor can retry their retained failed import', function () {
    Queue::fake();
    $record = failedArchiveImport($this->editor);
    Storage::disk('local')->put($record->source_path, 'archive bytes');

    Livewire::actingAs($this->editor)
        ->test(ArchiveImporter::class)
        ->call('retryImport', $record->id)
        ->assertHasNoErrors();

    $record->refresh();

    expect($record->status)->toBe(SeriesArchiveImport::STATUS_QUEUED)
        ->and($record->progress)->toBe(0)
        ->and($record->error)->toBeNull()
        ->and($record->completed_at)->toBeNull();

    Queue::assertPushed(ImportSeriesArchive::class, fn ($job) => $job->importId === $record->id
        && $job->queue === 'archive-imports');
});

test('an editor can discard only their terminal retained source', function () {
    $record = failedArchiveImport($this->editor);
    Storage::disk('local')->put($record->source_path, 'archive bytes');

    Livewire::actingAs($this->editor)
        ->test(ArchiveImporter::class)
        ->call('discardSource', $record->id)
        ->assertHasNoErrors();

    expect(Storage::disk('local')->exists('archive-imports/source/retry.koma.zip'))->toBeFalse()
        ->and($record->fresh()->source_path)->toBeNull()
        ->and($record->fresh()->message)->toContain('discarded');
});

test('another editor cannot retry or discard an import they do not own', function () {
    Queue::fake();
    $record = failedArchiveImport($this->editor);
    Storage::disk('local')->put($record->source_path, 'archive bytes');
    $other = User::factory()->create();
    $other->assignRole('Editor');

    expect(fn () => Livewire::actingAs($other)
        ->test(ArchiveImporter::class)
        ->call('retryImport', $record->id))
        ->toThrow(ModelNotFoundException::class);

    expect(fn () => Livewire::actingAs($other)
        ->test(ArchiveImporter::class)
        ->call('discardSource', $record->id))
        ->toThrow(ModelNotFoundException::class);

    expect(Storage::disk('local')->exists($record->source_path))->toBeTrue();
    Queue::assertNothingPushed();
});

function failedArchiveImport(User $user): SeriesArchiveImport
{
    return SeriesArchiveImport::query()->create([
        'id' => (string) str()->uuid(),
        'user_id' => $user->id,
        'source_name' => 'retry.koma.zip',
        'source_path' => 'archive-imports/source/retry.koma.zip',
        'status' => SeriesArchiveImport::STATUS_FAILED,
        'conflict_policy' => 'fail',
        'replace_confirmed' => false,
        'progress' => 12,
        'stage' => 'Worker stopped',
        'message' => 'Retry is available.',
        'error' => 'Worker stopped.',
        'completed_at' => now(),
    ]);
}
