<?php

use App\Media\ImageProcessor;
use App\Models\ApiToken;
use App\Models\Chapter;
use App\Models\Page;
use App\Models\Series;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->uploader = User::factory()->create();
    $this->uploader->assignRole('Uploader');
    $this->series = Series::create([
        'title' => 'Bulk API Test Series',
        'author' => 'Test Author',
        'status' => 'ongoing',
        'reading_direction' => 'rtl',
        'content_rating' => 'safe',
    ]);
    $this->plainTextToken = ApiToken::issue($this->uploader, 'Feature test')['plain_text_token'];

    Storage::fake('media');
});

test('an omitted publish flag follows the default chapter status setting', function () {
    app(App\Engine\Settings\Settings::class)->set('default_chapter_status', 'published');

    $this->withToken($this->plainTextToken)
        ->post(
            route('api.v1.series.chapters.bulk', $this->series),
            ['chapters' => [bulkApiChapterPayload('7')]],
            ['Accept' => 'application/json'],
        )
        ->assertSuccessful()
        ->assertJsonPath('data.chapters.0.status', 'published');
});

test('bulk endpoint requires a valid unexpired bearer token', function () {
    $url = route('api.v1.series.chapters.bulk', $this->series);
    $payload = bulkApiChapterPayload();

    $this->post($url, $payload, ['Accept' => 'application/json'])
        ->assertUnauthorized()
        ->assertJsonPath('message', 'A bearer token is required.');

    $this->withToken('not-a-real-token')
        ->post($url, $payload, ['Accept' => 'application/json'])
        ->assertUnauthorized()
        ->assertJsonPath('message', 'The bearer token is invalid or expired.');

    $expired = ApiToken::issue($this->uploader, 'Expired', now()->subMinute())['plain_text_token'];

    $this->withToken($expired)
        ->post($url, $payload, ['Accept' => 'application/json'])
        ->assertUnauthorized();

    expect(Chapter::query()->count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([]);
});

test('bulk endpoint enforces the existing upload chapters capability', function () {
    $member = User::factory()->create();
    $member->assignRole('Member');
    $memberToken = ApiToken::issue($member, 'Not allowed')['plain_text_token'];

    $this->withToken($memberToken)
        ->post(
            route('api.v1.series.chapters.bulk', $this->series),
            bulkApiChapterPayload(),
            ['Accept' => 'application/json'],
        )
        ->assertForbidden();

    expect(Chapter::query()->count())->toBe(0);
});

test('authorized uploader atomically creates ordered draft and published chapters', function () {
    $firstPage = UploadedFile::fake()->image('first.JPEG', 800, 1200)->size(400);
    $secondPage = UploadedFile::fake()->image('second.png', 900, 1400)->size(500);
    $thirdPage = UploadedFile::fake()->image('third.webp', 700, 1100)->size(300);

    $response = $this->withToken($this->plainTextToken)
        ->post(route('api.v1.series.chapters.bulk', $this->series), [
            'publish' => 'true',
            'chapters' => [
                [
                    'number' => '1',
                    'title' => 'Published by default',
                    'scanlation_group' => 'Koma QA',
                    'pages' => [$firstPage, $secondPage],
                ],
                [
                    'number' => '2.5',
                    'title' => 'Explicit draft',
                    'publish' => 'false',
                    'pages' => [$thirdPage],
                ],
            ],
        ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('meta.created', 2)
        ->assertJsonPath('meta.atomic', true)
        ->assertJsonPath('data.series.slug', $this->series->slug)
        ->assertJsonPath('data.chapters.0.number', '1')
        ->assertJsonPath('data.chapters.0.status', 'published')
        ->assertJsonPath('data.chapters.0.page_count', 2)
        ->assertJsonPath('data.chapters.0.pages.0.sort_order', 1)
        ->assertJsonPath('data.chapters.0.pages.1.sort_order', 2)
        ->assertJsonPath('data.chapters.1.number', '2.5')
        ->assertJsonPath('data.chapters.1.status', 'draft')
        ->assertJsonPath('data.chapters.1.page_count', 1);

    $chapters = Chapter::query()->with('pages')->orderBy('number')->get();
    $webp = app(ImageProcessor::class)->webpAvailable();

    expect($chapters)->toHaveCount(2)
        ->and($chapters[0]->scanlation_group)->toBe('Koma QA')
        ->and($chapters[0]->published_at)->not->toBeNull()
        ->and($chapters[0]->pages->pluck('image_path')->all())->toBe([
            "pages/{$this->series->id}/{$chapters[0]->id}/001.".($webp ? 'webp' : 'jpg'),
            "pages/{$this->series->id}/{$chapters[0]->id}/002.".($webp ? 'webp' : 'png'),
        ])
        ->and($chapters[1]->published_at)->toBeNull()
        ->and($chapters[1]->pages->first()->image_path)
        ->toBe("pages/{$this->series->id}/{$chapters[1]->id}/001.".($webp ? 'webp' : 'jpg'))
        ->and(ApiToken::query()->where('name', 'Feature test')->sole()->last_used_at)
        ->not->toBeNull();

    foreach ($chapters->flatMap->pages as $page) {
        Storage::disk('media')->assertExists($page->image_path);
        $bytes = Storage::disk('media')->get($page->image_path);
        $decoded = getimagesizefromstring($bytes);

        expect($decoded)->toBeArray()
            ->and($page->width)->toBe($decoded[0])
            ->and($page->height)->toBe($decoded[1])
            ->and($page->file_size)->toBe(strlen($bytes));
    }

    $this->withToken($this->plainTextToken)
        ->post(route('api.v1.series.chapters.bulk', $this->series), [
            'chapters' => [
                bulkApiChapterPayload('1'),
                bulkApiChapterPayload('2.5'),
            ],
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'chapters.0.number',
            'chapters.1.number',
        ]);

    expect(Chapter::query()->count())->toBe(2)
        ->and(Page::query()->count())->toBe(3);
});

test('bulk endpoint schedules chapters with batch defaults and per chapter overrides', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-07-19 12:00:00 UTC'));
    $batchTime = now()->addDay()->toAtomString();
    $overrideTime = now()->addDays(2)->toAtomString();

    $response = $this->withToken($this->plainTextToken)
        ->post(route('api.v1.series.chapters.bulk', $this->series), [
            'publish' => true,
            'publish_at' => $batchTime,
            'chapters' => [
                bulkApiChapterPayload('20'),
                array_merge(bulkApiChapterPayload('21'), ['publish_at' => $overrideTime]),
                array_merge(bulkApiChapterPayload('22'), [
                    'publish' => true,
                    'publish_at' => null,
                ]),
            ],
        ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('data.chapters.0.status', Chapter::STATUS_SCHEDULED)
        ->assertJsonPath('data.chapters.0.scheduled', true)
        ->assertJsonPath('data.chapters.0.published_at', $batchTime)
        ->assertJsonPath('data.chapters.1.published_at', $overrideTime)
        ->assertJsonPath('data.chapters.2.status', Chapter::STATUS_PUBLISHED)
        ->assertJsonPath('data.chapters.2.scheduled', false);

    expect(Chapter::scheduled()->count())->toBe(2)
        ->and(Chapter::published()->count())->toBe(1);
});

test('bulk endpoint rejects past schedules and publish false conflicts atomically', function () {
    $this->withToken($this->plainTextToken)
        ->post(route('api.v1.series.chapters.bulk', $this->series), [
            'publish' => false,
            'publish_at' => now()->addDay()->toAtomString(),
            'chapters' => [bulkApiChapterPayload('30')],
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['publish_at', 'chapters.0.publish_at']);

    $this->withToken($this->plainTextToken)
        ->post(route('api.v1.series.chapters.bulk', $this->series), [
            'chapters' => [
                array_merge(bulkApiChapterPayload('31'), [
                    'publish_at' => now()->subMinute()->toAtomString(),
                ]),
            ],
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['chapters.0.publish_at']);

    expect(Chapter::query()->count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([]);
});

test('validation rejects the entire batch before any chapter is stored', function () {
    $response = $this->withToken($this->plainTextToken)
        ->post(route('api.v1.series.chapters.bulk', $this->series), [
            'chapters' => [
                bulkApiChapterPayload('1'),
                bulkApiChapterPayload('2', [
                    UploadedFile::fake()->create('not-an-image.svg', 10, 'image/svg+xml'),
                ]),
            ],
        ], ['Accept' => 'application/json']);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['chapters.1.pages.0']);

    expect(Chapter::query()->count())->toBe(0)
        ->and(Page::query()->count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([]);

    $validPng = UploadedFile::fake()->image('animated.png', 200, 300);
    $animatedPng = UploadedFile::fake()->createWithContent(
        'animated.png',
        file_get_contents($validPng->getRealPath()).'acTL',
    );

    $this->withToken($this->plainTextToken)
        ->post(route('api.v1.series.chapters.bulk', $this->series), [
            'chapters' => [bulkApiChapterPayload('3', [$animatedPng])],
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath(
            'message',
            'Animated images are not supported. No chapters or page files were kept.',
        );

    expect(Chapter::query()->count())->toBe(0)
        ->and(Page::query()->count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([]);
});

test('duplicate and excessive batches are rejected without partial writes', function () {
    $this->series->chapters()->create(['number' => 9, 'status' => 'draft']);

    $this->withToken($this->plainTextToken)
        ->post(route('api.v1.series.chapters.bulk', $this->series), [
            'chapters' => [
                bulkApiChapterPayload('8'),
                bulkApiChapterPayload('9'),
            ],
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['chapters.1.number']);

    $this->withToken($this->plainTextToken)
        ->post(route('api.v1.series.chapters.bulk', $this->series), [
            'chapters' => [
                bulkApiChapterPayload('10'),
                bulkApiChapterPayload('10.0'),
            ],
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['chapters.1.number']);

    config()->set('koma.uploads.bulk_max_chapters', 2);

    $this->withToken($this->plainTextToken)
        ->post(route('api.v1.series.chapters.bulk', $this->series), [
            'chapters' => [
                bulkApiChapterPayload('10'),
                bulkApiChapterPayload('11'),
                bulkApiChapterPayload('12'),
            ],
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['chapters']);

    expect(Chapter::query()->pluck('number')->all())->toBe(['9.00'])
        ->and(Storage::disk('media')->allFiles())->toBe([]);
});

test('runtime failure in a later chapter rolls back all rows and files', function () {
    $createdPages = 0;

    Page::creating(function () use (&$createdPages) {
        if (++$createdPages === 2) {
            throw new RuntimeException('Intentional second-chapter failure.');
        }
    });

    try {
        $this->withToken($this->plainTextToken)
            ->post(route('api.v1.series.chapters.bulk', $this->series), [
                'publish' => true,
                'chapters' => [
                    bulkApiChapterPayload('1'),
                    bulkApiChapterPayload('2'),
                ],
            ], ['Accept' => 'application/json'])
            ->assertInternalServerError()
            ->assertJsonPath(
                'message',
                'The batch could not be saved. No chapters or page files were kept.',
            );
    } finally {
        Page::flushEventListeners();
    }

    expect(Chapter::query()->count())->toBe(0)
        ->and(Page::query()->count())->toBe(0)
        ->and(Storage::disk('media')->allFiles())->toBe([]);
});

test('token commands issue only to uploaders and revoke by token id', function () {
    $member = User::factory()->create();
    $member->assignRole('Member');

    $this->artisan('koma:api-token', ['email' => $member->email])
        ->expectsOutput('That user does not have the upload_chapters capability.')
        ->assertFailed();

    $this->artisan('koma:api-token', [
        'email' => $this->uploader->email,
        '--name' => 'CLI importer',
        '--expires' => now()->addDay()->toAtomString(),
    ])->assertSuccessful();

    $token = ApiToken::query()->where('name', 'CLI importer')->sole();

    $this->artisan('koma:api-token:revoke', ['id' => $token->id])
        ->expectsOutput('API token CLI importer was revoked.')
        ->assertSuccessful();

    expect($token->fresh())->toBeNull();
});

/**
 * @param  array<int, UploadedFile>|null  $pages
 * @return array<string, mixed>
 */
function bulkApiChapterPayload(string $number = '1', ?array $pages = null): array
{
    return [
        'number' => $number,
        'title' => "Chapter {$number}",
        'pages' => $pages ?? [UploadedFile::fake()->image("chapter-{$number}.jpg", 800, 1200)],
    ];
}
