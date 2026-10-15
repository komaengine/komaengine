<?php

namespace App\Publishing;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

class PageUploadRules
{
    /**
     * @return array<int, string>
     */
    public function get(): array
    {
        $mimes = implode(',', config('koma.uploads.page_mimes', ['jpg', 'jpeg', 'png', 'webp']));
        $maxKilobytes = (int) config('koma.uploads.page_max_kb', 20480);
        $maxWidth = (int) config('koma.uploads.page_max_width', 20000);
        $maxHeight = (int) config('koma.uploads.page_max_height', 20000);

        return [
            'image',
            'mimes:'.$mimes,
            'max:'.$maxKilobytes,
            "dimensions:min_width=1,min_height=1,max_width={$maxWidth},max_height={$maxHeight}",
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(string $attribute = 'page'): array
    {
        $maxMegabytes = (int) ceil((int) config('koma.uploads.page_max_kb', 20480) / 1024);
        $maxWidth = (int) config('koma.uploads.page_max_width', 20000);
        $maxHeight = (int) config('koma.uploads.page_max_height', 20000);

        return [
            "{$attribute}.image" => 'The selected page must be a valid JPEG, PNG, or WebP image.',
            "{$attribute}.mimes" => 'The selected page must be a JPEG, PNG, or WebP image.',
            "{$attribute}.max" => "The selected page exceeds the {$maxMegabytes} MB limit.",
            "{$attribute}.dimensions" => "The selected page exceeds the {$maxWidth}×{$maxHeight} source-dimension limit.",
        ];
    }

    public function firstFailureFor(UploadedFile $file): ?string
    {
        $validator = Validator::make(
            ['page' => $file],
            ['page' => $this->get()],
            $this->messages(),
        );

        return $validator->fails() ? $validator->errors()->first('page') : null;
    }
}
