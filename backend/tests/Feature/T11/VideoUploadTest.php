<?php

use App\Enums\VideoAssetStatus;
use App\Enums\VideoSource;
use App\Models\AuditLog;
use App\Models\Lesson;
use App\Models\User;
use App\Models\VideoAsset;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

test('tao phien upload: asset uploading, bai gan asset, tra tus target, audit', function () {
    $actor = vvCourseActor();
    $fake = vvVideoFake();
    [$course, , $lesson] = vvContentSet();
    $lesson->forceFill(['duration_seconds' => 99])->save();

    $res = vvRequestUpload($course, $lesson, ['filename' => 'C:\\fakepath\\../Bài 1.MP4'])->assertCreated()
        ->assertJsonStructure(['video_asset_id', 'status', 'upload' => ['protocol', 'tus_endpoint', 'headers' => ['AuthorizationSignature', 'AuthorizationExpire', 'VideoId', 'LibraryId'], 'expires_at']])
        ->assertJsonPath('status', 'uploading')->assertJsonPath('upload.protocol', 'tus');

    $asset = VideoAsset::query()->findOrFail($res->json('video_asset_id'));
    expect($asset->status)->toBe(VideoAssetStatus::Uploading)
        ->and($asset->lesson_id)->toBe($lesson->id)->and($asset->created_by)->toBe($actor->id)
        ->and($asset->provider)->toBe('fake')->and($asset->declared_size_bytes)->toBe(50 * 1024 * 1024)
        ->and($asset->original_filename)->toBe('Bài 1.MP4')
        ->and($res->json('upload.headers.VideoId'))->toBe($asset->provider_video_id)
        ->and($fake->has($asset->provider_video_id))->toBeTrue();

    $fresh = $lesson->fresh();
    expect($fresh->video_asset_id)->toBe($asset->id)->and($fresh->video_source)->toBe(VideoSource::Upload)->and($fresh->duration_seconds)->toBeNull();
    // TTL ≤ 6 giờ
    expect(now()->addHours(6)->addMinute()->greaterThan($res->json('upload.expires_at')))->toBeTrue();

    $log = AuditLog::query()->where('action', 'lesson.video_upload')->latest('id')->firstOrFail();
    expect($log->actor_id)->toBe($actor->id);
});

test('upload lai: asset cu tro thanh mo coi, bai tro asset moi; chuyen link ngoai xoa external', function () {
    vvCourseActor();
    vvVideoFake();
    [$course, , $lesson] = vvContentSet();
    $lesson->forceFill(['is_preview' => true, 'video_source' => 'external_link', 'external_provider' => 'youtube', 'external_video_id' => 'abcdefghijk'])->save();

    $first = vvRequestUpload($course, $lesson)->assertCreated()->json('video_asset_id');
    expect($lesson->fresh()->external_video_id)->toBeNull();
    $second = vvRequestUpload($course, $lesson)->assertCreated()->json('video_asset_id');

    expect($lesson->fresh()->video_asset_id)->toBe($second)->and(VideoAsset::query()->whereKey($first)->exists())->toBeTrue();
});

test('validate: size > 2GB, size 0, duoi file la, thieu truong -> 422; khong tao asset', function () {
    vvCourseActor();
    vvVideoFake();
    [$course, , $lesson] = vvContentSet();

    vvRequestUpload($course, $lesson, ['size' => 2048 * 1024 * 1024 + 1])->assertStatus(422)->assertJsonValidationErrors('size');
    vvRequestUpload($course, $lesson, ['size' => 0])->assertStatus(422)->assertJsonValidationErrors('size');
    vvRequestUpload($course, $lesson, ['filename' => 'virus.php'])->assertStatus(422)->assertJsonValidationErrors('filename');
    vvRequestUpload($course, $lesson, ['filename' => 'a.mp4.exe'])->assertStatus(422)->assertJsonValidationErrors('filename');
    vvCourseJson('POST', vvUploadPath($course, $lesson), [])->assertStatus(422)->assertJsonValidationErrors(['filename', 'size']);

    // đúng 2 GB thì được
    vvRequestUpload($course, $lesson, ['size' => 2048 * 1024 * 1024])->assertCreated();
    expect(VideoAsset::query()->count())->toBe(1);
});

test('han muc 20GB/ngay moi nguoi tao: vuot -> 422 VIDEO_QUOTA_EXCEEDED; nguoi khac/hom qua khong tinh', function () {
    $actor = vvCourseActor();
    vvVideoFake();
    [$course, , $lesson] = vvContentSet();

    $gb = 1024 * 1024 * 1024;
    VideoAsset::factory()->create(['created_by' => $actor->id, 'declared_size_bytes' => 19 * $gb]);
    VideoAsset::factory()->create(['created_by' => User::factory()->teacher(), 'declared_size_bytes' => 15 * $gb]);
    VideoAsset::factory()->create(['created_by' => $actor->id, 'declared_size_bytes' => 15 * $gb, 'created_at' => now()->subDay()->startOfDay()->subHour()]);

    vvRequestUpload($course, $lesson, ['size' => 2 * $gb])->assertStatus(422)->assertJsonPath('code', 'VIDEO_QUOTA_EXCEEDED');
    expect($lesson->fresh()->video_asset_id)->toBeNull();

    vvRequestUpload($course, $lesson, ['size' => 1 * $gb])->assertCreated(); // đúng 20 GB
    vvRequestUpload($course, $lesson, ['size' => 1])->assertStatus(422)->assertJsonPath('code', 'VIDEO_QUOTA_EXCEEDED');
});

test('nha cung cap loi -> 503 VIDEO_PROVIDER_UNAVAILABLE, khong tao asset, bai khong doi', function () {
    vvCourseActor();
    $fake = vvVideoFake();
    $fake->setUnavailable();
    [$course, , $lesson] = vvContentSet();

    vvRequestUpload($course, $lesson)->assertStatus(503)->assertJsonPath('code', 'VIDEO_PROVIDER_UNAVAILABLE');
    $asset = VideoAsset::query()->sole();
    expect($asset->status)->toBe(VideoAssetStatus::Failed)->and($lesson->fresh()->video_source)->toBe(VideoSource::None)
        ->and($lesson->fresh()->video_asset_id)->toBeNull();
});

test('provider internal/bunny chua co adapter -> 503, khong ro ri chi tiet', function () {
    vvCourseActor();
    config(['video.provider' => 'internal', 'video.enabled_providers' => ['internal']]);
    [$course, , $lesson] = vvContentSet();

    $res = vvRequestUpload($course, $lesson)->assertStatus(503)->assertJsonPath('code', 'VIDEO_PROVIDER_UNAVAILABLE');
    expect($res->getContent())->not->toContain('VideoLab')->and($lesson->fresh()->video_asset_id)->toBeNull()
        ->and(VideoAsset::query()->count())->toBe(0);
});

test('bai bi xoa trong luc goi provider -> 404, asset failed, don video o provider, bai khong doi', function () {
    vvCourseActor();
    $fake = vvVideoFake();
    [$course, , $lesson] = vvContentSet();
    $fake->afterCreate = fn () => $lesson->delete();

    vvRequestUpload($course, $lesson)->assertNotFound();

    $asset = VideoAsset::query()->sole();
    expect($asset->status)->toBe(VideoAssetStatus::Failed)->and($fake->deleted)->toHaveCount(1)
        ->and(Lesson::withTrashed()->find($lesson->id)->video_asset_id)->toBeNull();
});

test('R1: provider duoc goi ngoai transaction (khong giu khoa) va asset created giu cho han muc', function () {
    vvCourseActor();
    $fake = vvVideoFake();
    [$course, , $lesson] = vvContentSet();
    $seen = [];
    $fake->afterCreate = function () use (&$seen) {
        $seen = ['level' => DB::transactionLevel(), 'assets' => VideoAsset::query()->get(['status', 'declared_size_bytes'])->all()];
    };

    vvRequestUpload($course, $lesson, ['size' => 1000])->assertCreated();

    // RefreshDatabase bọc 1 transaction ngoài => mức nền là 1; trong lúc gọi provider không có tx nào thêm.
    expect($seen['level'])->toBe(1)->and($seen['assets'])->toHaveCount(1)
        ->and($seen['assets'][0]->status)->toBe(VideoAssetStatus::Created)->and($seen['assets'][0]->declared_size_bytes)->toBe(1000);
});

test('phan quyen: GV khong duoc gan 403, GV duoc gan 201, bai khoa khac 404, bai da xoa 404, chua dang nhap 401', function () {
    $teacher = vvCourseActor('teacher');
    vvVideoFake();
    [$mine, , $myLesson] = vvContentSet();
    [$other, , $otherLesson] = vvContentSet();

    vvRequestUpload($mine, $myLesson)->assertForbidden();
    vvCourseJson('GET', vvVideoPath($mine, $myLesson))->assertForbidden();
    vvAssign($mine, $teacher);
    vvRequestUpload($mine, $myLesson)->assertCreated();
    vvCourseJson('GET', vvVideoPath($mine, $myLesson))->assertOk();

    vvRequestUpload($mine, $otherLesson)->assertNotFound();
    vvCourseJson('GET', vvVideoPath($mine, $otherLesson))->assertNotFound();
    vvRequestUpload($other, $otherLesson)->assertForbidden();

    $myLesson->delete();
    vvRequestUpload($mine, $myLesson)->assertNotFound();
    expect(VideoAsset::query()->count())->toBe(1);
});

test('chua dang nhap -> 401', function () {
    [$course, , $lesson] = vvContentSet();

    test()->postJson(vvAdminUrl(vvUploadPath($course, $lesson)), ['filename' => 'a.mp4', 'size' => 1], vvAdminHeaders())->assertUnauthorized();
    test()->getJson(vvAdminUrl(vvVideoPath($course, $lesson)), vvAdminHeaders())->assertUnauthorized();
});

test('GET video: bai chua co video, dang upload, ready (khong lo provider id/header)', function () {
    vvCourseActor();
    vvVideoFake();
    [$course, , $lesson] = vvContentSet();

    vvCourseJson('GET', vvVideoPath($course, $lesson))->assertOk()
        ->assertJsonPath('has_video_asset', false)->assertJsonPath('status', null)->assertJsonPath('video_source', 'none');

    $id = vvRequestUpload($course, $lesson)->json('video_asset_id');
    $res = vvCourseJson('GET', vvVideoPath($course, $lesson))->assertOk()
        ->assertJsonPath('status', 'uploading')->assertJsonPath('video_asset_id', $id)->assertJsonPath('original_filename', 'bai1.mp4');
    expect(array_keys($res->json()))->toBe(['lesson_id', 'video_source', 'has_video_asset', 'video_asset_id', 'status', 'duration_seconds', 'original_filename', 'error_message', 'updated_at']);

    VideoAsset::query()->whereKey($id)->update(['status' => 'ready', 'duration_seconds' => 321]);
    vvCourseJson('GET', vvVideoPath($course, $lesson))->assertJsonPath('status', 'ready')->assertJsonPath('duration_seconds', 321);
    // LessonResource (T09) phản ánh cùng trạng thái
    expect(Lesson::query()->find($lesson->id)->video_asset_id)->toBe($id);
});

test('phan quyen: quan ly trang duoc upload (manageContent nhu admin); hoc sinh bi tu choi, khong tao asset', function () {
    vvVideoFake();
    [$course, , $lesson] = vvContentSet();

    vvCourseActor('pageManager');
    vvRequestUpload($course, $lesson)->assertCreated();
    vvCourseJson('GET', vvVideoPath($course, $lesson))->assertOk();
    expect(VideoAsset::query()->count())->toBe(1);

    auth()->forgetGuards();
    $this->actingAs(User::factory()->student()->create());
    $this->postJson(vvAdminUrl(vvUploadPath($course, $lesson)), ['filename' => 'a.mp4', 'size' => 10], vvAdminHeaders())->assertStatus(403);
    expect(VideoAsset::query()->count())->toBe(1);
});
