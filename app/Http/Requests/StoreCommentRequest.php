<?php

namespace App\Http\Requests;

use App\Community\CommentSpamGuard;
use App\Community\CommentText;
use App\Models\Comment;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Comment::class) === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => app(CommentText::class)->normalize($this->input('body'))]);
        }
    }

    public function rules(): array
    {
        return $this->bodyRules();
    }

    public function isSuspicious(): bool
    {
        return app(CommentSpamGuard::class)->isSuspicious(
            $this->input('comment_proof'),
            $this->input('website'),
        );
    }

    /** @return array<string, array<int, mixed>> */
    protected function bodyRules(): array
    {
        return [
            'body' => [
                'bail',
                'required',
                'string',
                'min:'.config('koma.comments.body_min_length', 2),
                'max:'.config('koma.comments.body_max_length', 2000),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $error = app(CommentText::class)->validationError((string) $value);

                    if ($error !== null) {
                        $fail($error);
                    }
                },
            ],
        ];
    }
}
