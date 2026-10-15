<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BulkChapterUploadRequest;
use App\Media\ImageProcessingException;
use App\Models\Chapter;
use App\Models\Series;
use App\Publishing\BulkChapterUploadService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Throwable;

class BulkChapterController extends Controller
{
    public function store(
        BulkChapterUploadRequest $request,
        Series $series,
        BulkChapterUploadService $uploads,
    ): JsonResponse {
        $validated = $request->validated();
        $defaultPublish = (bool) ($validated['publish']
            ?? (app(\App\Engine\Settings\Settings::class)->get('default_chapter_status', 'draft') === 'published'));
        $defaultPublishAt = $validated['publish_at'] ?? null;
        $chapters = array_map(function (array $chapter) use ($defaultPublish, $defaultPublishAt) {
            $publishAt = array_key_exists('publish_at', $chapter)
                ? $chapter['publish_at']
                : $defaultPublishAt;
            $chapter['publish_at'] = $publishAt
                ? CarbonImmutable::parse($publishAt)->utc()
                : null;
            $chapter['publish'] = $publishAt
                ? false
                : (bool) ($chapter['publish'] ?? $defaultPublish);

            return $chapter;
        }, $validated['chapters']);

        try {
            $created = $uploads->store($series, $chapters);
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'A chapter in this batch was created by another request. No chapters from this batch were kept.',
            ], 409);
        } catch (ImageProcessingException $exception) {
            if (! $exception->clientFault) {
                report($exception);
            }

            return response()->json([
                'message' => $exception->getMessage().' No chapters or page files were kept.',
            ], $exception->clientFault ? 422 : 500);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'The batch could not be saved. No chapters or page files were kept.',
            ], 500);
        }

        return response()->json([
            'data' => [
                'series' => [
                    'id' => $series->id,
                    'slug' => $series->slug,
                    'title' => $series->title,
                ],
                'chapters' => array_map($this->chapterData(...), $created),
            ],
            'meta' => [
                'created' => count($created),
                'atomic' => true,
            ],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    protected function chapterData(Chapter $chapter): array
    {
        return [
            'id' => $chapter->id,
            'number' => $chapter->displayNumber(),
            'title' => $chapter->title,
            'scanlation_group' => $chapter->scanlation_group,
            'language' => $chapter->language,
            'status' => $chapter->status,
            'scheduled' => $chapter->isScheduled(),
            'published_at' => $chapter->published_at?->toAtomString(),
            'page_count' => $chapter->pages->count(),
            'pages' => $chapter->pages->map(fn ($page) => [
                'id' => $page->id,
                'sort_order' => $page->sort_order,
                'url' => $page->url(),
                'width' => $page->width,
                'height' => $page->height,
                'file_size' => $page->file_size,
            ])->values()->all(),
        ];
    }
}
