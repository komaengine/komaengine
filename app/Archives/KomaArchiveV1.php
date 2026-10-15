<?php

namespace App\Archives;

final class KomaArchiveV1
{
    public const FORMAT = 'koma-archive';

    public const SCHEMA_VERSION = 1;

    public const MANIFEST_PATH = 'manifest.json';

    public const MIME_TYPE = 'application/vnd.koma.archive+zip';

    // ZIP's portable DOS timestamp floor. Stable entry times make logical
    // comparisons independent of local staging-file timestamps.
    public const ZIP_ENTRY_TIMESTAMP = 315532800;

    private function __construct() {}
}
