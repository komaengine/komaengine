<?php

namespace App\Http\Requests\Api;

use App\Models\Series;
use App\Publishing\PageUploadRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BulkChapterUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(PageUploadRules $pageUploadRules): array
    {
        $series = $this->route('series');
        $seriesId = $series instanceof Series ? $series->getKey() : null;
        $maxChapters = (int) config('koma.uploads.bulk_max_chapters', 25);
        $maxPages = (int) config('koma.uploads.bulk_max_pages_per_chapter', 500);

        return [
            'publish' => ['sometimes', 'boolean'],
            'publish_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'chapters' => ['required', 'array', 'min:1', 'max:'.$maxChapters],
            'chapters.*' => ['required', 'array'],
            'chapters.*.number' => [
                'required',
                'numeric',
                'min:0',
                'distinct',
                Rule::unique('chapters', 'number')->where('series_id', $seriesId),
            ],
            'chapters.*.title' => ['nullable', 'string', 'max:255'],
            'chapters.*.scanlation_group' => ['nullable', 'string', 'max:255'],
            'chapters.*.language' => ['nullable', 'string', 'max:35', 'regex:/\A[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*\z/'],
            'chapters.*.publish' => ['sometimes', 'boolean'],
            'chapters.*.publish_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'chapters.*.pages' => ['required', 'array', 'min:1', 'max:'.$maxPages],
            'chapters.*.pages.*' => array_merge(['required', 'file'], $pageUploadRules->get()),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $batchPublishWasFalse = $this->exists('publish') && $this->input('publish') === false;
            $batchPublishAt = $this->input('publish_at');

            if ($batchPublishWasFalse && filled($batchPublishAt)) {
                $validator->errors()->add(
                    'publish_at',
                    'A scheduled publication time cannot be combined with publish=false.',
                );
            }

            foreach ((array) $this->input('chapters', []) as $index => $chapter) {
                if (! is_array($chapter)) {
                    continue;
                }

                $publishAt = array_key_exists('publish_at', $chapter)
                    ? $chapter['publish_at']
                    : $batchPublishAt;
                $chapterPublishWasFalse = array_key_exists('publish', $chapter)
                    && $chapter['publish'] === false;

                if (filled($publishAt)
                    && ($chapterPublishWasFalse
                        || (! array_key_exists('publish', $chapter) && $batchPublishWasFalse))) {
                    $validator->errors()->add(
                        "chapters.{$index}.publish_at",
                        'A scheduled publication time cannot be combined with publish=false.',
                    );
                }
            }
        });
    }

    /**
     * Normalize common multipart boolean values before Laravel validates them.
     */
    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->has('publish')) {
            $payload['publish'] = $this->normalizeBoolean($this->input('publish'));
        }

        if (is_array($this->input('chapters'))) {
            $payload['chapters'] = array_map(function ($chapter) {
                if (is_array($chapter) && array_key_exists('publish', $chapter)) {
                    $chapter['publish'] = $this->normalizeBoolean($chapter['publish']);
                }

                return $chapter;
            }, $this->input('chapters'));
        }

        $this->merge($payload);
    }

    protected function normalizeBoolean(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            default => $value,
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'chapters.*.number.distinct' => 'A chapter number may appear only once in a batch.',
            'chapters.*.number.unique' => 'That chapter number already exists for this series.',
            'chapters.*.pages.required' => 'Every chapter must contain at least one page.',
            'publish_at.after' => 'The scheduled publication time must be in the future.',
            'chapters.*.publish_at.after' => 'The scheduled publication time must be in the future.',
        ];
    }
}
