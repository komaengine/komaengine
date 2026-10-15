<?php

namespace App\Archives;

use App\Models\Series;

final readonly class ImportedArchive
{
    /** @param array{series:int,genres:int,tags:int,chapters:int,pages:int,media:int} $counts */
    public function __construct(
        public Series $series,
        public array $counts,
    ) {}
}
