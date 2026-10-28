<?php

use App\Engine\Health\Heartbeats;
use Illuminate\Contracts\Console\Kernel;

require '/var/www/html/vendor/autoload.php';

$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

exit($app->make(Heartbeats::class)->lastSchedulerRun() === null ? 1 : 0);
