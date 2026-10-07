<?php

use App\Enums\VideoAssetStatus;
use App\Jobs\SyncVideoAssetStatusJob;
use App\Models\VideoAsset;
use App\Services\Video\Exceptions\VideoProviderException;
use App\Services\Video\VideoAssetSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/helpers.php';

test('webhook: lay trang thai that tu provider (ready), ghi thoi luong asset va bai', function () {
    [, $lesson, $asset, $fake] = vvLessonWithAsset();
    $fake->setStatus($asset->provider_video_id, VideoAssetStatus::Ready, 754);

    vvWebhook('fake', ['VideoGuid' => $asset->provider_video_id, 'Status' => 3])->assertNoContent();

    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Ready)->and($asset->fresh()->duration_seconds)->toBe(754)
        ->and($lesson->fresh()->duration_seconds)->toBe(754);
});

test('webhook khong tin payload: payload noi ready nhung provider noi uploading -> khong doi', function () {
    [, $lesson, $asset, $fake] = vvLessonWithAsset();
    $fake->setStatus($asset->provider_video_id, VideoAssetStatus::Uploading);

    vvWebhook('fake', ['VideoGuid' => $asset->provider_video_id, 'Status' => 'ready', 'status' => 'ready', 'duration' => 9999])->assertNoContent();

    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Uploading)->and($lesson->fresh()->duration_seconds)->toBeNull();
});

test('webhook: processing, failed co error_message; ready/failed la trang thai cuoi, khong lui', function () {
    [, , $asset, $fake] = vvLessonWithAsset();
    $guid = $asset->provider_video_id;

    $fake->setStatus($guid, VideoAssetStatus::Processing);
    vvWebhook('fake', ['VideoGuid' => $guid])->assertNoContent();
    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Processing);

    Cache::flush(); // T37 S1: webhook trùng của cùng asset trong cửa sổ ngắn được gom
    $fake->setStatus($guid, VideoAssetStatus::Failed, null, '<b>Định dạng không hỗ trợ</b>');
    vvWebhook('fake', ['VideoGuid' => $guid])->assertNoContent();
    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Failed)->and($asset->fresh()->error_message)->toBe('Định dạng không hỗ trợ');

    $fake->setStatus($guid, VideoAssetStatus::Ready, 10);
    vvWebhook('fake', ['VideoGuid' => $guid])->assertNoContent();
    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Failed);
});

test('webhook idempotent: goi 2 lan van ready, khong loi', function () {
    [, , $asset, $fake] = vvLessonWithAsset();
    $fake->setStatus($asset->provider_video_id, VideoAssetStatus::Ready, 60);

    vvWebhook('fake', ['VideoGuid' => $asset->provider_video_id])->assertNoContent();
    vvWebhook('fake', ['VideoGuid' => $asset->provider_video_id])->assertNoContent();

    expect($asset->fresh()->duration_seconds)->toBe(60);
});

test('webhook: bai da doi sang video khac thi khong ghi de thoi luong bai', function () {
    [, $lesson, $asset, $fake] = vvLessonWithAsset();
    $lesson->forceFill(['video_asset_id' => null, 'video_source' => 'none', 'duration_seconds' => 5])->save();
    $fake->setStatus($asset->provider_video_id, VideoAssetStatus::Ready, 600);

    vvWebhook('fake', ['VideoGuid' => $asset->provider_video_id])->assertNoContent();

    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Ready)->and($lesson->fresh()->duration_seconds)->toBe(5);
});

test('webhook: guid la/payload sai/provider ngoai allowlist', function () {
    vvVideoFake();

    vvWebhook('fake', ['VideoGuid' => '11111111-1111-1111-1111-111111111111'])->assertNoContent();
    vvWebhook('fake', ['VideoGuid' => '../../etc/passwd'])->assertNoContent();
    vvWebhook('fake', ['VideoGuid' => ['x']])->assertNoContent();
    vvWebhook('fake', [])->assertNoContent();
    vvWebhook('vimeo', ['VideoGuid' => '11111111-1111-1111-1111-111111111111'])->assertNotFound();
    vvWebhook("fake' or 1=1", [])->assertNotFound();

    // provider hợp lệ về mặt route nhưng chưa bật/chưa có adapter
    vvWebhook('bunny', ['VideoGuid' => '11111111-1111-1111-1111-111111111111'])->assertNotFound();
    vvWebhook('internal', ['VideoGuid' => '11111111-1111-1111-1111-111111111111'])->assertNotFound();
});

test('webhook: provider tren URL khac provider cua asset -> bo qua', function () {
    [, , $asset, $fake] = vvLessonWithAsset(assetOver: ['provider' => 'internal']);
    $fake->setStatus($asset->provider_video_id, VideoAssetStatus::Ready, 60);

    vvWebhook('fake', ['VideoGuid' => $asset->provider_video_id])->assertNoContent();

    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Uploading);
});

test('webhook: provider loi khi xac minh -> 503 de gui lai; khong can dang nhap, khong set cookie', function () {
    [, , $asset, $fake] = vvLessonWithAsset();
    $fake->setUnavailable();

    $res = vvWebhook('fake', ['VideoGuid' => $asset->provider_video_id])->assertStatus(503)->assertJsonPath('code', 'VIDEO_PROVIDER_UNAVAILABLE');
    expect($res->headers->getCookies())->toBe([])->and($asset->fresh()->status)->toBe(VideoAssetStatus::Uploading);
});

test('sync: provider bao khong ton tai -> failed', function () {
    [, , $asset, $fake] = vvLessonWithAsset();
    $fake->forget($asset->provider_video_id);

    // R4: asset mới (404 có thể là tạm) chưa bị đánh failed
    app(VideoAssetSyncService::class)->sync($asset);
    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Uploading);

    VideoAsset::query()->whereKey($asset->id)->update(['updated_at' => now()->subHour()]);
    app(VideoAssetSyncService::class)->sync($asset->fresh());

    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Failed);
});

test('videos:check-stuck: dong bo asset cu; upload qua han -> failed; processing qua han -> failed; moi tao thi bo qua', function () {
    [, , $recent, $fake] = vvLessonWithAsset();
    [, , $done] = vvLessonWithAsset(assetOver: ['updated_at' => now()->subHour()]);
    $fake->setStatus($done->provider_video_id, VideoAssetStatus::Ready, 42);
    [, , $stuckUpload] = vvLessonWithAsset(assetOver: ['created_at' => now()->subHours(8), 'updated_at' => now()->subHours(8)]);
    [, , $stuckProc] = vvLessonWithAsset(VideoAssetStatus::Processing, ['updated_at' => now()->subHours(5)]);
    [, , $okProc] = vvLessonWithAsset(VideoAssetStatus::Processing, ['updated_at' => now()->subHour()]);
    $fake->setStatus($stuckProc->provider_video_id, VideoAssetStatus::Processing);
    $fake->setStatus($okProc->provider_video_id, VideoAssetStatus::Processing);
    $fake->setStatus($stuckUpload->provider_video_id, VideoAssetStatus::Uploading);

    test()->artisan('videos:check-stuck', ['--dry-run' => true])->assertSuccessful();
    expect($done->fresh()->status)->toBe(VideoAssetStatus::Uploading);

    test()->artisan('videos:check-stuck')->assertSuccessful();

    expect($recent->fresh()->status)->toBe(VideoAssetStatus::Uploading)
        ->and($done->fresh()->status)->toBe(VideoAssetStatus::Ready)
        ->and($stuckUpload->fresh()->status)->toBe(VideoAssetStatus::Failed)
        ->and($stuckUpload->fresh()->error_message)->not->toBeNull()
        ->and($stuckProc->fresh()->status)->toBe(VideoAssetStatus::Failed)
        ->and($okProc->fresh()->status)->toBe(VideoAssetStatus::Processing);
});

test('videos:check-stuck: provider loi khong danh dau failed oan', function () {
    [, , $asset, $fake] = vvLessonWithAsset(assetOver: ['created_at' => now()->subHours(8), 'updated_at' => now()->subHours(8)]);
    $fake->setUnavailable();

    expect(fn () => test()->artisan('videos:check-stuck'))->toThrow(VideoProviderException::class);
    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Uploading);
});

test('R3: job bo qua provider khong con trong allowlist (khong retry), co timeout va unique theo asset', function () {
    [, , $asset] = vvLessonWithAsset();
    config(['video.enabled_providers' => ['internal']]);

    $job = new SyncVideoAssetStatusJob($asset->id);
    $job->handle(app(VideoAssetSyncService::class));

    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Uploading)
        ->and($job->timeout)->toBe(60)->and($job->uniqueId())->toBe((string) $asset->id)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class);
});
