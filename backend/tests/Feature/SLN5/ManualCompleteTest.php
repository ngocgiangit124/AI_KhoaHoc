<?php

use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\VideoSource;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../T13/helpers.php';

function vvComplete(int|Lesson $lesson)
{
    $id = $lesson instanceof Lesson ? $lesson->id : $lesson;

    return test()->postJson(vvApiUrl("/learn/lessons/{$id}/complete"), [], vvWebHeaders());
}

/** Thêm bài link ngoài vào khóa của bộ dữ liệu T13. */
function vvExternalLesson(array $s, int $position = 2): Lesson
{
    return vvAddLesson($s['course'], $s['chapter'], $position, [
        'video_source' => VideoSource::ExternalLink,
        'external_provider' => 'youtube',
        'external_video_id' => 'dQw4w9WgXcQ',
    ]);
}

test('bai link ngoai: danh dau da hoc -> completed, course_percent cap nhat', function () {
    $s = vvLearnSet();
    $ext = vvExternalLesson($s);

    vvComplete($ext)->assertOk()->assertExactJson(['status' => 'completed', 'completed' => true, 'course_percent' => 50]);

    $row = vvProgressRow($s['student'], $ext);
    expect($row->status)->toBe('completed')->and($row->completed_at)->not->toBeNull();
});

test('idempotent: goi lai khong doi completed_at', function () {
    $s = vvLearnSet();
    $ext = vvExternalLesson($s);
    $this->freezeTime();
    vvComplete($ext)->assertOk();
    $first = vvProgressRow($s['student'], $ext)->completed_at;

    $this->travel(2)->minutes();
    vvComplete($ext)->assertOk()->assertJsonPath('completed', true)->assertJsonPath('course_percent', 50);
    expect(vvProgressRow($s['student'], $ext)->completed_at)->toBe($first);
});

test('bai video tai len bi tu choi 422 LESSON_COMPLETION_NOT_MANUAL, khong ghi tien do', function () {
    $s = vvLearnSet();

    vvComplete($s['lesson'])->assertStatus(422)->assertJsonPath('code', 'LESSON_COMPLETION_NOT_MANUAL');
    expect(vvProgressRow($s['student'], $s['lesson']))->toBeNull();
});

test('chua ghi danh -> 403 COURSE_NOT_OWNED kem course khi khoa published', function () {
    $s = vvLearnSet(owned: false);
    $ext = vvExternalLesson($s);

    vvComplete($ext)->assertForbidden()
        ->assertJsonPath('code', 'COURSE_NOT_OWNED')
        ->assertJsonPath('errors.course.id', $s['course']->id)
        ->assertJsonPath('errors.course.slug', $s['course']->slug)
        ->assertJsonPath('errors.course.title', $s['course']->title);
    expect(vvProgressRow($s['student'], $ext))->toBeNull();
});

test('khoa het hieu luc (enrollment khong active) -> 403', function () {
    $s = vvLearnSet();
    $ext = vvExternalLesson($s);
    vvSetEnrollment($s['student'], $s['course'], EnrollmentStatus::Revoked);

    vvComplete($ext)->assertForbidden()->assertJsonPath('code', 'COURSE_NOT_OWNED');
    expect(vvProgressRow($s['student'], $ext))->toBeNull();
});

test('phien bi thay the -> 401 SESSION_REPLACED', function () {
    $s = vvLearnSet();
    $ext = vvExternalLesson($s);
    User::query()->whereKey($s['student']->id)->update(['current_session_id' => 'other-session', 'current_device_id' => (string) Str::uuid()]);
    app('auth')->forgetGuards();
    test()->actingAs(User::query()->findOrFail($s['student']->id));

    vvComplete($ext)->assertStatus(401)->assertJsonPath('code', 'SESSION_REPLACED');
    expect(vvProgressRow($s['student'], $ext))->toBeNull();
});

test('bai khong ton tai / da xoa mem -> 404', function () {
    $s = vvLearnSet();
    $ext = vvExternalLesson($s);
    $ext->delete();

    vvComplete($ext)->assertNotFound();
    vvComplete(999999)->assertNotFound();
});

test('403 COURSE_NOT_OWNED o cac endpoint learn: co course khi published, khong lo khi khoa nhap/an', function () {
    $s = vvLearnSet(owned: false);
    // Bài không preview của khóa published: 403 kèm course.
    vvLearnGet("/learn/lessons/{$s['lesson']->id}")->assertForbidden()
        ->assertJsonPath('errors.course.slug', $s['course']->slug);
    vvLearnGet("/learn/courses/{$s['course']->id}")->assertForbidden()
        ->assertJsonPath('errors.course.id', $s['course']->id);
    vvLearnGet("/learn/lessons/{$s['lesson']->id}/playback")->assertForbidden()
        ->assertJsonPath('errors.course.title', $s['course']->title);
    vvHeartbeat($s['lesson'], 5, 5)->assertForbidden()
        ->assertJsonPath('errors.course.id', $s['course']->id);

    // Khóa nháp + chưa sở hữu: heartbeat và complete đều 404 (không dò được ID bài khóa chưa công bố).
    $s2 = vvLearnSet(owned: false);
    $s2['course']->forceFill(['status' => CourseStatus::Draft])->save();
    $ext = vvExternalLesson($s2);
    vvHeartbeat($s2['lesson'], 5, 5)->assertNotFound();
    vvComplete($ext)->assertNotFound();
    vvLearnGet("/learn/lessons/{$s2['lesson']->id}")->assertNotFound();

    // Đã ghi danh rồi khóa bị ẩn: giữ quyền (BR4), vẫn hoàn thành được.
    $s3 = vvLearnSet(owned: true);
    $s3['course']->forceFill(['status' => CourseStatus::Draft])->save();
    vvComplete(vvExternalLesson($s3))->assertOk()->assertJsonPath('completed', true);
});

test('bai da co dong in_progress -> update thanh completed, giu watched_seconds', function () {
    $s = vvLearnSet();
    $ext = vvExternalLesson($s);
    DB::table('lesson_progress')->insert([
        'user_id' => $s['student']->id, 'lesson_id' => $ext->id, 'course_id' => $s['course']->id,
        'watched_seconds' => 7, 'last_position_seconds' => 3, 'status' => 'in_progress',
        'last_accessed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    vvComplete($ext)->assertOk()->assertJsonPath('completed', true);
    $row = vvProgressRow($s['student'], $ext);
    expect($row->status)->toBe('completed')->and($row->watched_seconds)->toBe(7)->and($row->completed_at)->not->toBeNull()
        ->and(DB::table('lesson_progress')->where('lesson_id', $ext->id)->count())->toBe(1);
});

test('chuong hoac khoa xoa mem -> 404, khong ghi tien do', function () {
    $s = vvLearnSet();
    $ext = vvExternalLesson($s);
    $s['chapter']->delete();
    vvComplete($ext)->assertNotFound();

    $t = vvLearnSet();
    $ext2 = vvExternalLesson($t);
    $t['course']->delete();
    vvComplete($ext2)->assertNotFound();

    expect(DB::table('lesson_progress')->count())->toBe(0);
});

test('doi nguon: upload -> link ngoai duoc complete; link ngoai -> upload bi 422 (ca khi da in_progress); bai none -> 422', function () {
    $s = vvLearnSet();
    $s['lesson']->forceFill(['video_source' => VideoSource::ExternalLink, 'external_provider' => 'youtube', 'external_video_id' => 'abc'])->save();
    vvComplete($s['lesson'])->assertOk();

    $ext = vvExternalLesson($s);
    DB::table('lesson_progress')->insert([
        'user_id' => $s['student']->id, 'lesson_id' => $ext->id, 'course_id' => $s['course']->id,
        'watched_seconds' => 0, 'last_position_seconds' => 0, 'status' => 'in_progress',
        'last_accessed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $ext->forceFill(['video_source' => VideoSource::Upload])->save();
    vvComplete($ext)->assertStatus(422)->assertJsonPath('code', 'LESSON_COMPLETION_NOT_MANUAL');
    expect(vvProgressRow($s['student'], $ext)->status)->toBe('in_progress');

    $none = vvAddLesson($s['course'], $s['chapter'], 3, ['video_source' => VideoSource::None]);
    vvComplete($none)->assertStatus(422)->assertJsonPath('code', 'LESSON_COMPLETION_NOT_MANUAL');
});

test('da complete luc la link ngoai, doi sang upload van giu completed (khong reset)', function () {
    $s = vvLearnSet();
    $ext = vvExternalLesson($s);
    vvComplete($ext)->assertOk();
    $ext->forceFill(['video_source' => VideoSource::Upload])->save();

    expect(vvProgressRow($s['student'], $ext)->status)->toBe('completed');
    vvComplete($ext)->assertStatus(422);
    expect(vvProgressRow($s['student'], $ext)->status)->toBe('completed');
});

test('throttle: qua 6 lan/phut/bai -> 429', function () {
    $s = vvLearnSet();
    $ext = vvExternalLesson($s);
    for ($i = 0; $i < 6; $i++) {
        vvComplete($ext)->assertOk();
    }
    vvComplete($ext)->assertStatus(429);
});
