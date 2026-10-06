<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/helpers.php';

/**
 * R2 (review T36): hành vi thật của migration `create_teacher_profiles_table` (backfill + down()).
 *
 * DDL (DROP/CREATE TABLE) tự commit ngầm trong MySQL, nên test này KHÔNG được bọc trong transaction của RefreshDatabase: dữ liệu
 * tạo ra là dữ liệu thật trên DB test và PHẢI dọn trong `finally` (xoá user thì dòng hồ sơ bị xoá theo FK cascade, rồi đảm bảo bảng
 * `teacher_profiles` tồn tại trở lại).
 */
function vvT36Migration(): Migration
{
    return require database_path('migrations/2026_10_18_100000_create_teacher_profiles_table.php');
}

function vvT36RestoreTable(array $userIds): void
{
    if (! Schema::hasTable('teacher_profiles')) {
        vvT36Migration()->up();
    }

    if ($userIds !== []) {
        DB::table('users')->whereIn('id', $userIds)->delete();
    }
}

test('R2: backfill chep users.bio/avatar_path cua giao vien sang teacher_profiles, KHONG chep dong y, bo qua nguoi khong co bio/anh va hoc sinh', function () {
    $ids = [];

    try {
        vvT36Migration()->down();
        expect(Schema::hasTable('teacher_profiles'))->toBeFalse();

        $long = User::factory()->teacher()->create(['bio' => '  '.str_repeat('é', 700).'  ']);
        $avatarOnly = User::factory()->teacher()->create(['avatar_path' => 'dddddddd-1111-4222-8333-444444444444.webp']);
        $blankBio = User::factory()->teacher()->create(['bio' => '   ']);
        $none = User::factory()->teacher()->create();
        $student = User::factory()->student()->create(['bio' => 'Học sinh có bio', 'avatar_path' => 'eeeeeeee-1111-4222-8333-444444444444.webp']);
        $admin = User::factory()->admin()->create(['bio' => 'Admin có bio']);
        $both = User::factory()->teacher()->create(['bio' => "Dòng 1\r\nDòng 2", 'avatar_path' => 'ffffffff-1111-4222-8333-444444444444.webp']);
        $ids = [$long->id, $avatarOnly->id, $blankBio->id, $none->id, $student->id, $admin->id, $both->id];

        vvT36Migration()->up();

        $rows = DB::table('teacher_profiles')->whereIn('user_id', $ids)->get()->keyBy('user_id');

        // Giáo viên có bio hoặc ảnh: có dòng (blankBio có dòng nhưng bio NULL, vì bio khác NULL ở nguồn).
        expect($rows->keys()->sort()->values()->all())->toBe(collect([$long->id, $avatarOnly->id, $blankBio->id, $both->id])->sort()->values()->all());

        expect(mb_strlen($rows[$long->id]->bio))->toBe(600)->and($rows[$long->id]->bio)->toStartWith('é')->and($rows[$long->id]->avatar_path)->toBeNull();
        expect($rows[$avatarOnly->id]->bio)->toBeNull()->and($rows[$avatarOnly->id]->avatar_path)->toBe('dddddddd-1111-4222-8333-444444444444.webp');
        expect($rows[$blankBio->id]->bio)->toBeNull();
        expect($rows[$both->id]->avatar_path)->toBe('ffffffff-1111-4222-8333-444444444444.webp')->and($rows[$both->id]->bio)->toContain('Dòng 1');

        foreach ($rows as $row) {
            expect($row->public_consent_at)->toBeNull()->and($row->public_consent_version)->toBeNull()->and($row->public_consent_withdrawn_at)->toBeNull()
                ->and((int) $row->show_on_homepage)->toBe(0)->and($row->homepage_order)->toBeNull()->and($row->profile_updated_by)->toBeNull()
                ->and($row->created_at)->not->toBeNull();
        }

        // Cột cũ giữ nguyên (xoá ở release sau, T36-1).
        expect(DB::table('users')->where('id', $long->id)->value('bio'))->not->toBeNull();
    } finally {
        vvT36RestoreTable($ids);
    }
});

test('R2: down() xoa bang va 2 CHECK, users.bio/avatar_path con nguyen; up() lai duoc va CHECK hoat dong lai', function () {
    $ids = [];

    try {
        $teacher = User::factory()->teacher()->create(['bio' => 'Giữ nguyên', 'avatar_path' => 'aaaaaaaa-1111-4222-8333-444444444444.webp']);
        $ids[] = $teacher->id;
        DB::table('teacher_profiles')->insertOrIgnore(['user_id' => $teacher->id, 'created_at' => now(), 'updated_at' => now()]);

        vvT36Migration()->down();

        expect(Schema::hasTable('teacher_profiles'))->toBeFalse();
        $checks = DB::select("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME LIKE 'chk_teacher_profiles%'");
        expect($checks)->toBe([]);
        expect(Schema::hasColumns('users', ['bio', 'avatar_path']))->toBeTrue();
        expect(DB::table('users')->where('id', $teacher->id)->value('bio'))->toBe('Giữ nguyên');

        // down() gọi lần hai (bảng đã mất) không lỗi.
        vvT36Migration()->down();

        vvT36Migration()->up();

        expect(Schema::hasTable('teacher_profiles'))->toBeTrue();
        $checks = collect(DB::select("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME LIKE 'chk_teacher_profiles%'"))->pluck('CONSTRAINT_NAME')->sort()->values()->all();
        expect($checks)->toBe(['chk_teacher_profiles_consent_version', 'chk_teacher_profiles_homepage_order']);
        expect(fn () => DB::table('teacher_profiles')->insert(['user_id' => $teacher->id, 'homepage_order' => 0, 'created_at' => now(), 'updated_at' => now()]))
            ->toThrow(QueryException::class);
        // Dữ liệu hồ sơ đã mất ở down() (đúng thiết kế): backfill lại từ users.bio khi up().
        expect(DB::table('teacher_profiles')->where('user_id', $teacher->id)->value('bio'))->toBe('Giữ nguyên');
    } finally {
        vvT36RestoreTable($ids);
    }
});
