<?php

use App\Enums\VideoAssetStatus;
use App\Models\VideoAsset;
use App\Services\Video\Data\PlaybackContext;
use App\Services\Video\Exceptions\VideoProviderException;
use App\Services\Video\VideoProviderManager;
use App\Support\ProductionConfigGuard;

require_once __DIR__.'/helpers.php';

test('manager: chi resolve provider trong allowlist', function () {
    config(['video.provider' => 'fake', 'video.enabled_providers' => ['fake']]);
    $manager = app(VideoProviderManager::class);

    expect($manager->driver()->name())->toBe('fake');
    expect(fn () => $manager->driver('bunny'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $manager->driver('../x'))->toThrow(InvalidArgumentException::class);
});

test('manager: internal/bunny chua co adapter -> VideoProviderException', function () {
    config(['video.enabled_providers' => ['internal', 'bunny']]);
    $manager = app(VideoProviderManager::class);

    expect(fn () => $manager->driver('internal'))->toThrow(VideoProviderException::class);
    expect(fn () => $manager->driver('bunny'))->toThrow(VideoProviderException::class);
});

test('fake provider: playback tra HLS co han, token rang IP', function () {
    $fake = vvVideoFake();
    $asset = VideoAsset::factory()->make(['provider_video_id' => 'abc', 'status' => VideoAssetStatus::Ready]);

    $a = $fake->playback($asset, new PlaybackContext(1, '1.2.3.4', 900));
    $b = $fake->playback($asset, new PlaybackContext(1, '5.6.7.8', 900));

    expect($a->kind)->toBe('hls')->and($a->url)->toContain('/abc/playlist.m3u8')->and($a->url)->not->toBe($b->url)
        ->and($a->expiresAt->getTimestamp())->toBeGreaterThan(now()->getTimestamp());
});

test('production guard: fake video bi cam', function () {
    app()->detectEnvironment(fn () => 'production');
    config([
        'app.debug' => false, 'session.secure' => true, 'captcha.driver' => 'turnstile',
        'sanctum.stateful' => ['vitaminvui.vn'], 'app.trusted_proxies' => '10.0.0.1',
        'payments.enabled_gateways' => ['momo'], 'payments.gateways.momo.endpoint' => 'https://payment.momo.vn/x',
        'video.provider' => 'internal', 'video.enabled_providers' => ['internal'],
    ]);
    expect(fn () => (new ProductionConfigGuard)->check())->not->toThrow(RuntimeException::class);

    config(['video.enabled_providers' => ['internal', 'FAKE']]);
    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);

    config(['video.enabled_providers' => ['internal'], 'video.provider' => 'fake']);
    expect(fn () => (new ProductionConfigGuard)->check())->toThrow(RuntimeException::class);
});
