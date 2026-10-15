<?php

namespace App\Archives;

final class KomaArchiveV2
{
    public const FORMAT = KomaArchiveV1::FORMAT;

    public const SCHEMA_VERSION = 2;

    public const MANIFEST_PATH = KomaArchiveV1::MANIFEST_PATH;

    public const MIME_TYPE = KomaArchiveV1::MIME_TYPE;

    public const ZIP_ENTRY_TIMESTAMP = KomaArchiveV1::ZIP_ENTRY_TIMESTAMP;

    private function __construct() {}
}
