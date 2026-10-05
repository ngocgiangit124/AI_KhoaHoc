<?php

/*
|--------------------------------------------------------------------------
| Captcha (US-001/US-015 — T03/T27; boot guard M4 đọc 'driver' ở đây)
|--------------------------------------------------------------------------
|
| Mặc định `turnstile` (fail-safe). `fake` chỉ hợp lệ ở local/testing — .env.example/phpunit.xml đặt `fake` (CaptchaVerifier thật hiện thực ở T03).
|
*/

return [
    'driver' => env('CAPTCHA_DRIVER', 'turnstile'),
];
