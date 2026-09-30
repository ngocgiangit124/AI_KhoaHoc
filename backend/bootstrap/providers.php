<?php

use App\Providers\AppServiceProvider;
use App\Providers\CounterServiceProvider;
use App\Providers\PaymentServiceProvider;

return [
    AppServiceProvider::class,
    PaymentServiceProvider::class,
    CounterServiceProvider::class,
];
