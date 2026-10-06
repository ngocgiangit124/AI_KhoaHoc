<?php

use App\VideoLab\VideoLabServiceProvider;

require_once __DIR__.'/helpers.php';

function vlGuard(): void
{
    (new VideoLabServiceProvider(app()))->guardProductionConfig();
}

function vlGoodConfig(): array
{
    return [
        'videolab.api_key' => str_repeat('a', 40), 'videolab.token_key' => str_repeat('b', 40),
        'videolab.webhook_secret' => str_repeat('c', 40), 'videolab.public_url' => 'https://video.vitaminvui.vn',
    ];
}

test('guard: cau hinh day du ngoai local/testing -> khong loi', function () {
    app()->detectEnvironment(fn () => 'production');
    config(vlGoodConfig());

    expect(fn () => vlGuard())->not->toThrow(RuntimeException::class);
});

test('guard: thieu khoa hoac khoa < 32 ky tu -> loi (production va staging)', function (string $env, string $key, mixed $value) {
    app()->detectEnvironment(fn () => $env);
    config(vlGoodConfig());
    config([$key => $value]);

    expect(fn () => vlGuard())->toThrow(RuntimeException::class);
})->with(function () {
    foreach (['production', 'staging'] as $env) {
        foreach (['videolab.api_key', 'videolab.token_key', 'videolab.webhook_secret'] as $key) {
            yield "{$env} {$key} null" => [$env, $key, null];
            yield "{$env} {$key} ngan" => [$env, $key, 'short'];
        }
    }
});

test('guard: public_url http -> loi; local/testing bo qua guard', function () {
    app()->detectEnvironment(fn () => 'staging');
    config(vlGoodConfig());
    config(['videolab.public_url' => 'http://video.vitaminvui.vn']);
    expect(fn () => vlGuard())->toThrow(RuntimeException::class);

    app()->detectEnvironment(fn () => 'local');
    config(['videolab.api_key' => null]);
    expect(fn () => vlGuard())->not->toThrow(RuntimeException::class);
});
