<?php

use App\Providers\AppServiceProvider;
use App\Providers\OperationsServiceProvider;
use App\Providers\VideoServiceProvider;
use App\VideoLab\VideoLabServiceProvider;

return [
    AppServiceProvider::class,
    VideoServiceProvider::class,
    OperationsServiceProvider::class,
    VideoLabServiceProvider::class,
];
