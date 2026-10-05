<?php

use App\Enums\VideoAssetStatus;
use App\Models\VideoAsset;
use App\Services\Video\OrphanVideoPruner;
use Illuminate\Console\Scheduling\Schedule;

require_once __DIR__.'/helpers.php';

test('bai doi sang none/external qua API -> asset (ke ca processing) thanh mo coi va bi don', function () {
    vvCourseActor();
    $fake = vvVideoFake();
    [$course, $chapter, $lesson] = vvContentSet();

    $id = vvRequestUpload($course, $lesson)->json('video_asset_id');
    $asset = VideoAsset::query()->findOrFail($id);
    $asset->forceFill(['status' => VideoAssetStatus::Processing, 'created_at' => now()->subHour()])->save();

    vvCourseJson('PUT', vvLessonPath($course, $chapter, $lesson), ['video_source' => 'none'])->assertOk();
    expect($lesson->fresh()->video_asset_id)->toBeNull()->and(VideoAsset::query()->whereKey($id)->exists())->toBeTrue();

    test()->artisan('videos:prune-orphans')->assertSuccessful();

    expect(VideoAsset::query()->whereKey($id)->exists())->toBeFalse()->and($fake->deleted)->toContain($asset->provider_video_id);
});

test('khong don asset con duoc bai tro toi; don asset cua bai da xoa mem; ton trong grace', function () {
    $fake = vvVideoFake();
    [, $attachedLesson, $attached] = vvLessonWithAsset();
    [, $deletedLesson, $ofDeleted] = vvLessonWithAsset();
    $deletedLesson->delete();
    $fresh = VideoAsset::factory()->create(['provider' => 'fake', 'provider_video_id' => $fake->createVideo('x')->guid]);
    $fresh->forceFill(['created_at' => now()->subHour()])->save();
    $young = VideoAsset::factory()->create(['provider' => 'fake', 'provider_video_id' => $fake->createVideo('y')->guid]); // vừa tạo

    VideoAsset::query()->whereIn('id', [$attached->id, $ofDeleted->id])->update(['created_at' => now()->subHour()]);

    test()->artisan('videos:prune-orphans', ['--dry-run' => true])->assertSuccessful();
    expect(VideoAsset::query()->count())->toBe(4);

    test()->artisan('videos:prune-orphans')->assertSuccessful();

    expect(VideoAsset::query()->pluck('id')->all())->toContain($attached->id, $young->id)
        ->and(VideoAsset::query()->whereKey($ofDeleted->id)->exists())->toBeFalse()
        ->and(VideoAsset::query()->whereKey($fresh->id)->exists())->toBeFalse();
});

test('nha cung cap loi khi xoa -> giu dong de thu lai lan sau; provider xoa khong ton tai van xoa dong', function () {
    $fake = vvVideoFake();
    $a = VideoAsset::factory()->create(['provider' => 'fake', 'provider_video_id' => $fake->createVideo('a')->guid, 'created_at' => now()->subHour()]);

    $fake->setUnavailable();
    test()->artisan('videos:prune-orphans')->assertSuccessful();
    expect(VideoAsset::query()->whereKey($a->id)->exists())->toBeTrue();

    $fake->setUnavailable(false);
    $fake->forget($a->provider_video_id);
    test()->artisan('videos:prune-orphans')->assertSuccessful();
    expect(VideoAsset::query()->whereKey($a->id)->exists())->toBeFalse();
});

test('lich: check-stuck va prune-orphans duoc dang ky', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');

    expect($events)->toContain('videos:check-stuck')->and($events)->toContain('videos:prune-orphans');
});

test('R2: kiem tra mo coi dduoi khoa TRUOC khi xoa o provider; asset giu cho (pending) chi xoa dong; dem skipped', function () {
    $fake = vvVideoFake();
    [, , $attached] = vvLessonWithAsset();
    $attached->forceFill(['created_at' => now()->subHour()])->save();
    $pending = VideoAsset::factory()->create(['provider' => 'fake', 'provider_video_id' => 'pending-abc', 'created_at' => now()->subHour()]);

    $pruner = app(OrphanVideoPruner::class);
    // Giả lập "đã được gắn lại" ngay trước khi khoá: gọi pruneOne qua reflection trên asset đang có bài trỏ tới.
    $m = new ReflectionMethod($pruner, 'pruneOne');
    expect($m->invoke($pruner, $attached))->toBe('skipped')->and($fake->deleted)->toBe([]);

    $stats = $pruner->prune();
    expect($stats['deleted'])->toBe(1)->and($stats['skipped'])->toBe(0)->and($fake->deleted)->toBe([])
        ->and(VideoAsset::query()->whereKey($pending->id)->exists())->toBeFalse()
        ->and(VideoAsset::query()->whereKey($attached->id)->exists())->toBeTrue();
});
