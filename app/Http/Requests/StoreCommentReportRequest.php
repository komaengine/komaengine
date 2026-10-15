<?php

namespace App\Http\Requests;

use App\Models\CommentReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommentReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('report_details'))) {
            $this->merge(['report_details' => trim($this->input('report_details'))]);
        }
    }

    public function rules(): array
    {
        return [
            'report_reason' => ['required', 'string', Rule::in(array_keys(CommentReport::REASONS))],
            'report_details' => [
                'nullable',
                'string',
                'max:'.config('koma.comments.report_details_max_length', 1000),
            ],
        ];
    }
}
