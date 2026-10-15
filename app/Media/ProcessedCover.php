<?php

namespace App\Media;

final readonly class ProcessedCover
{
    public function __construct(
        public ProcessedImage $cover,
        public ProcessedImage $thumbnail,
    ) {}
}
