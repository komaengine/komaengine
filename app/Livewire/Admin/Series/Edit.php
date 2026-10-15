<?php

namespace App\Livewire\Admin\Series;

use App\Engine\Settings\Settings;
use App\Media\ImageProcessingException;
use App\Media\ImageProcessor;
use App\Media\PreparedCover;
use App\Media\SeriesCoverService;
use App\Models\Chapter;
use App\Models\Genre;
use App\Models\Series as SeriesModel;
use App\Models\Tag;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

#[Layout('components.layouts.admin')]
class Edit extends Component
{
    use WithFileUploads;

    public ?SeriesModel $series = null;

    public bool $creating = true;

    public string $title = '';

    public ?string $author = null;

    public ?string $artist = null;

    public ?int $year = null;

    public ?string $publication_date = null;

    public ?string $work_format = null;

    /** @var list<array{language:string,title:string,type:string}> */
    public array $alternateTitles = [];

    /** @var list<array{name:string,role:string,type:string}> */
    public array $credits = [];

    public string $franchisesText = '';

    public string $charactersText = '';

    /** @var list<array{provider:string,external_id:string,url:?string}> */
    public array $sourceReferences = [];

    public string $status = 'ongoing';

    public string $reading_direction = 'rtl';

    public ?string $default_reader_mode = null;

    public string $content_rating = 'safe';

    public bool $is_public = false;

    public ?string $synopsis = null;

    /** @var array<int, int> selected genre ids */
    public array $selectedGenres = [];

    /** @var array<int, int> selected tag ids */
    public array $selectedTags = [];

    /** @var array<int, string> chapter id => app-local datetime input */
    public array $scheduleTimes = [];

    /** @var array<int, string|null> chapter id => BCP 47 language code */
    public array $chapterLanguages = [];

    public $cover;

    public float $coverCropX = 0.5;

    public float $coverCropY = 0.5;

    public float $coverCropZoom = 1.0;

    public function mount(?SeriesModel $series = null): void
    {
        if ($series && $series->exists) {
            $this->series = $series;
            $this->creating = false;
            $this->title = $series->title;
            $this->author = $series->author;
            $this->artist = $series->artist;
            $this->year = $series->year;
            $this->publication_date = $series->publication_date?->format('Y-m-d');
            $this->work_format = $series->work_format;
            $this->alternateTitles = array_values($series->alternate_titles ?? []);
            $this->credits = array_values($series->credits ?? []);
            $this->franchisesText = implode("\n", $series->franchises ?? []);
            $this->charactersText = implode("\n", $series->characters ?? []);
            $this->sourceReferences = array_values($series->source_references ?? []);
            $this->status = $series->status;
            $this->reading_direction = $series->reading_direction;
            $this->default_reader_mode = $series->default_reader_mode;
            $this->content_rating = $series->content_rating;
            $this->is_public = $series->is_public;
            $this->synopsis = $series->synopsis;
            $this->selectedGenres = $series->genres->pluck('id')->all();
            $this->selectedTags = $series->tags->pluck('id')->all();
            $this->scheduleTimes = $series->chapters()
                ->scheduled()
                ->get()
                ->mapWithKeys(fn (Chapter $chapter) => [
                    $chapter->id => $chapter->published_at
                        ->timezone(config('app.timezone'))
                        ->format('Y-m-d\TH:i'),
                ])
                ->all();
            $this->chapterLanguages = $series->chapters()
                ->pluck('language', 'id')
                ->all();
        } else {
            $settings = app(Settings::class);

            $direction = $settings->get('default_reading_direction', 'rtl');
            $this->reading_direction = in_array($direction, SeriesModel::DIRECTIONS, true)
                ? $direction
                : 'rtl';

            $this->is_public = $settings->get('default_series_visibility', 'private') === 'public';

            $rating = $settings->get('default_content_rating', 'safe');
            $this->content_rating = in_array($rating, SeriesModel::RATINGS, true)
                ? $rating
                : 'safe';
        }
    }

    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'author' => ['nullable', 'string', 'max:255'],
            'artist' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'publication_date' => ['nullable', 'date_format:Y-m-d'],
            'work_format' => ['nullable', 'string', 'max:100', 'regex:/\A[a-z0-9]+(?:[_-][a-z0-9]+)*\z/'],
            'alternateTitles' => ['array', 'max:100'],
            'alternateTitles.*.language' => ['required', 'string', 'max:35', 'regex:/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*\z/'],
            'alternateTitles.*.title' => ['required', 'string', 'max:255'],
            'alternateTitles.*.type' => ['required', 'in:'.implode(',', SeriesModel::TITLE_TYPES)],
            'credits' => ['array', 'max:100'],
            'credits.*.name' => ['required', 'string', 'max:255'],
            'credits.*.role' => ['required', 'string', 'max:50', 'regex:/\A[a-z0-9]+(?:_[a-z0-9]+)*\z/'],
            'credits.*.type' => ['required', 'in:'.implode(',', SeriesModel::CREDIT_TYPES)],
            'franchisesText' => ['nullable', 'string', 'max:10000'],
            'charactersText' => ['nullable', 'string', 'max:50000'],
            'sourceReferences' => ['array', 'max:50'],
            'sourceReferences.*.provider' => ['required', 'string', 'max:100', 'regex:/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/'],
            'sourceReferences.*.external_id' => ['required', 'string', 'max:255'],
            'sourceReferences.*.url' => ['nullable', 'string', 'max:2048', 'regex:/\Ahttps?:\/\/[^\s]+\z/u'],
            'status' => ['required', 'in:'.implode(',', SeriesModel::STATUSES)],
            'reading_direction' => ['required', 'in:'.implode(',', SeriesModel::DIRECTIONS)],
            'default_reader_mode' => ['nullable', 'in:'.implode(',', SeriesModel::READER_MODES)],
            'content_rating' => ['required', 'in:'.implode(',', SeriesModel::RATINGS)],
            'is_public' => ['required', 'boolean'],
            'synopsis' => ['nullable', 'string', 'max:5000'],
            'selectedGenres' => ['array'],
            'selectedGenres.*' => ['integer', 'exists:genres,id'],
            'selectedTags' => ['array'],
            'selectedTags.*' => ['integer', 'exists:tags,id'],
            'chapterLanguages' => ['array'],
            'chapterLanguages.*' => ['nullable', 'string', 'max:35', 'regex:/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*\z/'],
            'cover' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:8192',
                'dimensions:min_width=1,min_height=1,max_width=20000,max_height=20000',
            ],
            'coverCropX' => ['required', 'numeric', 'between:0,1'],
            'coverCropY' => ['required', 'numeric', 'between:0,1'],
            'coverCropZoom' => ['required', 'numeric', 'between:1,3'],
        ];
    }

    public function toggleGenre(int $id): void
    {
        if (in_array($id, $this->selectedGenres, true)) {
            $this->selectedGenres = array_values(array_diff($this->selectedGenres, [$id]));
        } else {
            $this->selectedGenres[] = $id;
        }
    }

    public function toggleTag(int $id): void
    {
        if (in_array($id, $this->selectedTags, true)) {
            $this->selectedTags = array_values(array_diff($this->selectedTags, [$id]));
        } else {
            $this->selectedTags[] = $id;
        }
    }

    public function addAlternateTitle(): void
    {
        $this->alternateTitles[] = ['language' => '', 'title' => '', 'type' => 'alternate'];
    }

    public function removeAlternateTitle(int $index): void
    {
        unset($this->alternateTitles[$index]);
        $this->alternateTitles = array_values($this->alternateTitles);
    }

    public function addCredit(): void
    {
        $this->credits[] = ['name' => '', 'role' => 'artist', 'type' => 'person'];
    }

    public function removeCredit(int $index): void
    {
        unset($this->credits[$index]);
        $this->credits = array_values($this->credits);
    }

    public function addSourceReference(): void
    {
        $this->sourceReferences[] = ['provider' => '', 'external_id' => '', 'url' => null];
    }

    public function removeSourceReference(int $index): void
    {
        unset($this->sourceReferences[$index]);
        $this->sourceReferences = array_values($this->sourceReferences);
    }

    public function save()
    {
        $data = $this->validate();

        $series = $this->series ?? new SeriesModel;
        $oldCoverPath = $series->cover_path;
        $oldThumbnailPath = $series->cover_thumbnail_path;
        $covers = app(SeriesCoverService::class);
        $prepared = null;

        if ($this->cover) {
            try {
                $prepared = $covers->prepare(
                    $this->cover,
                    (float) $data['coverCropX'],
                    (float) $data['coverCropY'],
                    (float) $data['coverCropZoom'],
                );
            } catch (ImageProcessingException $e) {
                $this->addError('cover', $e->getMessage());

                return;
            } catch (Throwable $e) {
                report($e);
                $this->addError('cover', 'The cover and thumbnail could not be stored. The existing cover is unchanged.');

                return;
            }
        }

        try {
            DB::transaction(function () use ($series, $data, $prepared) {
                $series->fill([
                    'title' => $data['title'],
                    'author' => $data['author'],
                    'artist' => $data['artist'],
                    'alternate_titles' => array_values($data['alternateTitles']),
                    'credits' => array_values($data['credits']),
                    'franchises' => $this->lines($data['franchisesText']),
                    'characters' => $this->lines($data['charactersText']),
                    'source_references' => array_values($data['sourceReferences']),
                    'year' => $data['year'],
                    'publication_date' => filled($data['publication_date']) ? $data['publication_date'] : null,
                    'work_format' => filled($data['work_format']) ? $data['work_format'] : null,
                    'status' => $data['status'],
                    'reading_direction' => $data['reading_direction'],
                    'default_reader_mode' => filled($data['default_reader_mode']) ? $data['default_reader_mode'] : null,
                    'content_rating' => $data['content_rating'],
                    'is_public' => $data['is_public'],
                    'synopsis' => $data['synopsis'],
                ]);

                if ($prepared instanceof PreparedCover) {
                    $series->fill($prepared->attributes());
                }

                $series->save();
                $series->genres()->sync($this->selectedGenres);
                $series->tags()->sync($this->selectedTags);

                foreach ($data['chapterLanguages'] as $chapterId => $language) {
                    $series->chapters()
                        ->whereKey((int) $chapterId)
                        ->update(['language' => filled($language) ? $language : null]);
                }
            });
        } catch (Throwable $e) {
            if ($prepared instanceof PreparedCover) {
                $covers->discard($prepared);
            }

            report($e);
            $this->addError(
                $prepared ? 'cover' : 'title',
                'The series could not be saved. No media changes were kept.',
            );

            return;
        }

        if ($prepared instanceof PreparedCover) {
            $covers->deletePrevious($oldCoverPath, $oldThumbnailPath);
        }

        session()->flash('status', $this->creating ? 'Series created.' : 'Series saved.');

        return redirect()->route('admin.series.edit', $series);
    }

    public function togglePublish(int $chapterId): void
    {
        $chapter = $this->series?->chapters()->find($chapterId);

        if (! $chapter) {
            return;
        }

        if ($chapter->isPublished()) {
            $chapter->unpublish();
        } else {
            $chapter->publish();
        }

        unset($this->scheduleTimes[$chapterId]);
    }

    public function scheduleChapter(int $chapterId): void
    {
        $validated = $this->validate([
            "scheduleTimes.{$chapterId}" => ['required', 'date_format:Y-m-d\TH:i', 'after:now'],
        ], [], ["scheduleTimes.{$chapterId}" => 'publication time']);

        $chapter = $this->series?->chapters()->find($chapterId);

        if (! $chapter) {
            return;
        }

        $publishAt = CarbonImmutable::createFromFormat(
            'Y-m-d\TH:i',
            data_get($validated, "scheduleTimes.{$chapterId}"),
            config('app.timezone'),
        )->utc();

        $chapter->scheduleFor($publishAt);
        $this->scheduleTimes[$chapterId] = $chapter->published_at
            ->timezone(config('app.timezone'))
            ->format('Y-m-d\TH:i');

        session()->flash('status', "Chapter {$chapter->displayNumber()} scheduled.");
    }

    public function cancelSchedule(int $chapterId): void
    {
        $chapter = $this->series?->chapters()->find($chapterId);

        if (! $chapter?->isScheduled()) {
            return;
        }

        $chapter->unpublish();
        unset($this->scheduleTimes[$chapterId]);

        session()->flash('status', "Chapter {$chapter->displayNumber()} returned to draft.");
    }

    public function deleteChapter(int $chapterId): void
    {
        $chapter = $this->series?->chapters()->with('pages')->find($chapterId);

        if (! $chapter) {
            return;
        }

        foreach ($chapter->pages as $page) {
            Storage::disk('media')->delete($page->image_path);
        }

        $chapter->delete();
        session()->flash('status', 'Chapter deleted.');
    }

    public function render()
    {
        $genres = Genre::orderBy('name')->get();
        $tags = Tag::orderBy('name')->get();

        $chapters = $this->series
            ? $this->series->chapters()->withCount('pages')->orderByDesc('number')->get()
            : collect();

        return view('livewire.admin.series.edit', [
            'genres' => $genres,
            'tags' => $tags,
            'chapters' => $chapters,
            'imageCapabilities' => app(ImageProcessor::class)->capabilities(),
        ]);
    }

    /** @return list<string> */
    private function lines(?string $value): array
    {
        $lines = preg_split('/\R/u', (string) $value) ?: [];
        $lines = array_map('trim', $lines);

        return array_values(array_unique(array_filter($lines), SORT_STRING));
    }
}
