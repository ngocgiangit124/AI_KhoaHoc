<?php

use App\Enums\VideoAssetStatus;
use App\Models\Lesson;
use App\Models\VideoAsset;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../T13/helpers.php';

test('AC1: upload qua API tao video o Bunny, luu provider/library/guid, tra TUS co chu ky, khong lo khoa API', function () {
    vvCourseActor();
    bzConfigure();
    config(['video.library_id' => 'default']);
    bzFakeApi(['POST '.bzVideosPath() => Http::response(bzVideoBody(), 200)]);
    [$course, , $lesson] = vvContentSet();

    $res = vvRequestUpload($course, $lesson)->assertCreated()
        ->assertJsonPath('upload.tus_endpoint', 'https://video.bunnycdn.com/tusupload')
        ->assertJsonPath('upload.headers.LibraryId', BZ_LIB)
        ->assertJsonPath('upload.headers.VideoId', BZ_GUID);

    $asset = VideoAsset::query()->sole();
    expect($asset->provider)->toBe('bunny')->and($asset->provider_library_id)->toBe(BZ_LIB)->and($asset->provider_video_id)->toBe(BZ_GUID)
        ->and($asset->status)->toBe(VideoAssetStatus::Uploading)->and($lesson->fresh()->video_asset_id)->toBe($asset->id);
    expect($res->getContent())->not->toContain(BZ_API_KEY)->and($res->getContent())->not->toContain(BZ_TOKEN_KEY);
});

test('AC11: Bunny 5xx / timeout / 401 -> 503 tieng Viet, han muc khong bi tru oan, khong de lai asset treo', function (string $kind) {
    $reply = match ($kind) {
        '500' => Http::response('err', 500),
        '401' => Http::response('no', 401),
        default => fn () => throw new ConnectionException('timeout'),
    };
    vvCourseActor();
    bzConfigure();
    config(['video.daily_quota_gb' => 1]);
    Log::spy();
    bzFakeApi(['POST '.bzVideosPath() => $reply]);
    [$course, , $lesson] = vvContentSet();
    $size = 1024 * 1024 * 1024;

    $res = vvRequestUpload($course, $lesson, ['size' => $size])->assertStatus(503)->assertJsonPath('code', 'VIDEO_PROVIDER_UNAVAILABLE');
    expect($res->getContent())->not->toContain(BZ_API_KEY)->and($lesson->fresh()->video_asset_id)->toBeNull()
        ->and(VideoAsset::query()->sole()->status)->toBe(VideoAssetStatus::Failed);

    bzFakeApi(['POST '.bzVideosPath() => Http::response(bzVideoBody(), 200)]);
    vvRequestUpload($course, $lesson, ['size' => $size])->assertCreated(); // đủ hạn mức dù lần trước thất bại
})->with([
    '500' => ['500'],
    'timeout' => ['timeout'],
    '401' => ['401'],
]);

test('AC11: tao xong nhung lay TUS loi -> xoa video vua tao o Bunny (khong mo coi)', function () {
    vvCourseActor();
    bzConfigure(['tus_endpoint' => '']);
    bzFakeApi([
        'POST '.bzVideosPath() => Http::response(bzVideoBody(), 200),
        'DELETE '.bzVideosPath('/'.BZ_GUID) => Http::response([], 200),
    ]);
    [$course, , $lesson] = vvContentSet();

    vvRequestUpload($course, $lesson)->assertStatus(503);

    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/videos/'.BZ_GUID));
    expect($lesson->fresh()->video_asset_id)->toBeNull();
});

test('AC14/BR: thieu cau hinh Bunny -> 503, khong goi mang, khong tao asset', function () {
    vvCourseActor();
    bzConfigure(['token_key' => null]);
    Http::fake();
    [$course, , $lesson] = vvContentSet();

    $res = vvRequestUpload($course, $lesson)->assertStatus(503)->assertJsonPath('code', 'VIDEO_PROVIDER_UNAVAILABLE');

    Http::assertNothingSent();
    expect(VideoAsset::query()->count())->toBe(0)->and($res->getContent())->not->toContain('BUNNY_');
});

test('AC3: webhook chi dung VideoGuid; trang thai that lay lai tu API (payload noi Status sai van bi bo qua)', function () {
    bzConfigure();
    [, $lesson, $asset] = bzLessonWithAsset();
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response(bzVideoBody(status: 4, length: 125), 200)]);

    bzWebhook(['VideoGuid' => BZ_GUID, 'Status' => 3, 'VideoLibraryId' => 777])->assertNoContent();

    $asset->refresh();
    expect($asset->status)->toBe(VideoAssetStatus::Ready)->and($asset->duration_seconds)->toBe(125)->and($lesson->fresh()->duration_seconds)->toBe(125);
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET' && $r->header('AccessKey') === [BZ_API_KEY]);

    // Bunny gửi lại (idempotent): vẫn ready.
    bzWebhook(['VideoGuid' => BZ_GUID, 'Status' => 4])->assertNoContent();
    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Ready);
});

test('AC3: payload bao Finished nhung Bunny noi dang ma hoa -> khong thanh ready', function () {
    bzConfigure();
    [, , $asset] = bzLessonWithAsset();
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response(bzVideoBody(status: 3), 200)]);

    bzWebhook(['VideoGuid' => BZ_GUID, 'Status' => 4])->assertNoContent();

    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Processing);
});

test('AC4: guid la / payload sai dinh dang -> 204, khong goi Bunny, khong doi du lieu', function (mixed $payload) {
    bzConfigure();
    [, , $asset] = bzLessonWithAsset();
    Http::fake();

    bzWebhook(is_array($payload) ? $payload : [])->assertNoContent();

    Http::assertNothingSent();
    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Uploading);
})->with([
    'guid khong ton tai' => [['VideoGuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'Status' => 4]],
    'sai dinh dang' => [['VideoGuid' => 'abc']],
    'thieu' => [['Status' => 4]],
    'mang' => [['VideoGuid' => ['x']]],
]);

test('webhook bunny khi chua bat/chua du cau hinh -> 404; Bunny loi khi xac minh -> 503 de gui lai', function () {
    config(['video.provider' => 'internal', 'video.enabled_providers' => ['internal']]);
    bzWebhook(['VideoGuid' => BZ_GUID])->assertNotFound();

    bzConfigure(['api_key' => null]);
    bzWebhook(['VideoGuid' => BZ_GUID])->assertNotFound();

    bzConfigure();
    bzLessonWithAsset();
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response('x', 502)]);
    bzWebhook(['VideoGuid' => BZ_GUID])->assertStatus(503);
});

test('AC5/AC15: dong bo ma 5/6 -> failed tieng Viet; ma la 7 -> van processing', function (int $code, VideoAssetStatus $expected) {
    bzConfigure();
    [, , $asset] = bzLessonWithAsset();
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response(bzVideoBody(status: $code), 200)]);

    bzWebhook(['VideoGuid' => BZ_GUID])->assertNoContent();

    $asset->refresh();
    expect($asset->status)->toBe($expected);
    if ($expected === VideoAssetStatus::Failed) {
        expect($asset->error_message)->toContain('Vui lòng')->and($asset->error_message)->not->toContain('http');
    }
})->with([[5, VideoAssetStatus::Failed], [6, VideoAssetStatus::Failed], [7, VideoAssetStatus::Processing], [8, VideoAssetStatus::Processing]]);

test('videos:check-stuck dong bo asset Bunny qua API', function () {
    bzConfigure();
    [, , $asset] = bzLessonWithAsset(VideoAssetStatus::Processing);
    VideoAsset::query()->whereKey($asset->id)->update(['updated_at' => now()->subHour()]);
    bzFakeApi(['GET '.bzVideosPath('/'.BZ_GUID) => Http::response(bzVideoBody(status: 4, length: 30), 200)]);

    test()->artisan('videos:check-stuck')->assertSuccessful();

    expect($asset->fresh()->status)->toBe(VideoAssetStatus::Ready);
});

test('AC10: don mo coi xoa video o Bunny; 404 coi la xong; loi 5xx giu dong de thu lai', function () {
    bzConfigure();
    [, $lesson, $asset] = bzLessonWithAsset();
    $lesson->delete();
    VideoAsset::query()->whereKey($asset->id)->update(['created_at' => now()->subHour()]);
    $path = 'DELETE '.bzVideosPath('/'.BZ_GUID);

    bzFakeApi([$path => Http::response('x', 500)]);
    test()->artisan('videos:prune-orphans')->assertSuccessful();
    expect(VideoAsset::query()->whereKey($asset->id)->exists())->toBeTrue();

    bzFakeApi([$path => Http::response(['message' => 'not found'], 404)]);
    test()->artisan('videos:prune-orphans')->assertSuccessful();
    expect(VideoAsset::query()->whereKey($asset->id)->exists())->toBeFalse();
});

test('AC6/AC13: bunny va video cu cung bat -> moi bai phat dung nha cung cap cua video do', function () {
    $s = vvLearnSet();
    bzConfigure(enabled: ['bunny', 'internal', 'fake']);
    config([
        'videolab.enabled' => true, 'videolab.api_key' => str_repeat('a', 40), 'videolab.token_key' => str_repeat('b', 40),
        'videolab.public_url' => 'https://video.vitaminvui.test',
    ]);
    $s['asset']->forceFill(['provider' => 'bunny', 'provider_video_id' => BZ_GUID])->save();

    $other = Lesson::factory()->for($s['course'])->for($s['chapter'])->create(['position' => 2, 'video_source' => 'upload', 'duration_seconds' => 50]);
    $old = VideoAsset::factory()->ready(50)->create(['provider' => 'internal', 'lesson_id' => $other->id]);
    $other->forceFill(['video_asset_id' => $old->id])->save();

    $bunny = vvLearnGet("/learn/lessons/{$s['lesson']->id}/playback", ['REMOTE_ADDR' => '203.0.113.7'])->assertOk();
    $internal = vvLearnGet("/learn/lessons/{$other->id}/playback")->assertOk();

    expect($bunny->json('url'))->toStartWith('https://'.BZ_CDN.'/bcdn_token=')->and($bunny->json('url'))->toEndWith('/'.BZ_GUID.'/playlist.m3u8')
        ->and($bunny->json('url'))->not->toContain(BZ_TOKEN_KEY)
        ->and($internal->json('url'))->toContain('/videolab/cdn/')->and($internal->json('url'))->not->toContain(BZ_CDN);
    expect(strtotime($bunny->json('expires_at')))->toBeBetween(time() + 14 * 60, time() + 15 * 60 + 5);
});

test('playback Bunny: cau hinh hong -> 503 VIDEO_PROVIDER_UNAVAILABLE, khong lo khoa', function () {
    $s = vvLearnSet();
    bzConfigure(['token_key' => null]);
    $s['asset']->forceFill(['provider' => 'bunny', 'provider_video_id' => BZ_GUID])->save();

    $res = vvLearnGet("/learn/lessons/{$s['lesson']->id}/playback")->assertStatus(503)->assertJsonPath('code', 'VIDEO_PROVIDER_UNAVAILABLE');
    expect($res->getContent())->not->toContain('BUNNY_');
});

test('prune hon hop: video bunny va fake xoa dung nha cung cap cua tung video', function () {
    $fake = vvVideoFake();
    bzConfigure(enabled: ['bunny', 'fake']);
    [, $l1, $a1] = bzLessonWithAsset();
    $guid = $fake->createVideo('x')->guid;
    $l2 = Lesson::factory()->create();
    $a2 = VideoAsset::factory()->create(['provider' => 'fake', 'provider_video_id' => $guid, 'lesson_id' => $l2->id]);
    $l1->delete();
    $l2->delete();
    VideoAsset::query()->whereIn('id', [$a1->id, $a2->id])->update(['created_at' => now()->subHour()]);
    bzFakeApi(['DELETE '.bzVideosPath('/'.BZ_GUID) => Http::response([], 200)]);

    test()->artisan('videos:prune-orphans')->assertSuccessful();

    expect(VideoAsset::query()->count())->toBe(0)->and($fake->deleted)->toBe([$guid]);
    Http::assertSentCount(1);
});
