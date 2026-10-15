<?php

namespace Tests\Fixtures\Plugins;

use App\Engine\Plugins\Contracts\Plugin;
use RuntimeException;

class BrokenPlugin implements Plugin
{
    public function register(): void
    {
        throw new RuntimeException('Intentional registration failure.');
    }

    public function boot(): void
    {
        //
    }
}
