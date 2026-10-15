<?php

namespace App\Archives;

final class ArchiveImportPolicy
{
    public const FAIL = 'fail';

    public const CREATE_UNIQUE = 'create_unique';

    public const REPLACE = 'replace';

    public const VALUES = [self::FAIL, self::CREATE_UNIQUE, self::REPLACE];

    private function __construct() {}
}
