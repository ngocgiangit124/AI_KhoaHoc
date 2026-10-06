<?php

use App\Enums\ConsentType;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/helpers.php';

function vvT36RawProfile(int $userId, array $attrs = []): bool
{
    return DB::table('teacher_profiles')->insert(['user_id' => $userId, 'created_at' => now(), 'updated_at' => now()] + $attrs);
}

test('schema: du cot, PK user_id, index homepage ten tuong minh', function () {
    expect(Schema::hasColumns('teacher_profiles', [
        'user_id', 'headline', 'bio', 'avatar_path', 'public_consent_at', 'public_consent_version', 'public_consent_withdrawn_at',
        'show_on_homepage', 'homepage_order', 'profile_updated_by', 'profile_updated_at', 'created_at', 'updated_at',
    ]))->toBeTrue();

    $indexes = collect(DB::select('SHOW INDEX FROM teacher_profiles'))->groupBy('Key_name');
    expect($indexes)->toHaveKeys(['PRIMARY', 'ix_teacher_profiles_homepage']);
    expect($indexes['PRIMARY']->pluck('Column_name')->all())->toBe(['user_id']);
    expect($indexes['ix_teacher_profiles_homepage']->pluck('Column_name')->all())->toBe(['show_on_homepage', 'homepage_order']);
});

test('CHECK chk_teacher_profiles_homepage_order: ngoai 1..999 bi DB tu choi (null va 1, 999 qua)', function () {
    $u = User::factory()->teacher()->create();
    expect(fn () => vvT36RawProfile($u->id, ['homepage_order' => 0]))->toThrow(QueryException::class);
    expect(fn () => vvT36RawProfile($u->id, ['homepage_order' => 1000]))->toThrow(QueryException::class);

    foreach ([null, 1, 999] as $v) {
        $x = User::factory()->teacher()->create();
        expect(vvT36RawProfile($x->id, ['homepage_order' => $v]))->toBeTrue();
    }
});

test('CHECK chk_teacher_profiles_consent_version: dong y ma khong co phien ban bi DB tu choi', function () {
    $u = User::factory()->teacher()->create();
    expect(fn () => vvT36RawProfile($u->id, ['public_consent_at' => now(), 'public_consent_version' => null]))->toThrow(QueryException::class);

    expect(vvT36RawProfile($u->id, ['public_consent_at' => now(), 'public_consent_version' => '2026-10']))->toBeTrue();
});

test('PK user_id: khong tao trung dong; xoa user -> cascade xoa ho so; profile_updated_by null on delete', function () {
    $u = User::factory()->teacher()->create();
    $editor = User::factory()->admin()->create();
    vvT36RawProfile($u->id, ['profile_updated_by' => $editor->id]);

    expect(fn () => vvT36RawProfile($u->id))->toThrow(QueryException::class);

    // INSERT IGNORE (đường tạo lười) bỏ qua trùng, không lỗi.
    expect(DB::table('teacher_profiles')->insertOrIgnore(['user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]))->toBe(0);

    DB::table('users')->where('id', $editor->id)->delete();
    expect(DB::table('teacher_profiles')->where('user_id', $u->id)->value('profile_updated_by'))->toBeNull();

    DB::table('users')->where('id', $u->id)->delete();
    expect(DB::table('teacher_profiles')->where('user_id', $u->id)->exists())->toBeFalse();
});

test('S17: TeacherProfile fillable chi gom headline, bio; mass-assign cot khac bi bo qua / loi strict', function () {
    $model = new TeacherProfile;

    expect($model->getFillable())->toBe(['headline', 'bio']);
    expect(fn () => $model->fill(['avatar_path' => 'x.webp']))->toThrow(MassAssignmentException::class);
    expect(fn () => $model->fill(['public_consent_at' => now()]))->toThrow(MassAssignmentException::class);
    expect(fn () => $model->fill(['show_on_homepage' => true]))->toThrow(MassAssignmentException::class);
});

test('S17: User khong con nhan bio qua mass-assign (ADR-005), bio khong nam trong fillable', function () {
    expect((new User)->getFillable())->not->toContain('bio')->not->toContain('avatar_path');
});

test('UserFactory::teacher()->withPublicProfile(): du anh, headline, bio, dong y theo phien ban hien hanh; tuy chon bat trang chu', function () {
    $t = User::factory()->teacher()->withPublicProfile(true, 3)->create();

    $p = vvT36Profile($t);
    expect($p->avatar_path)->not->toBeNull()->and($p->bio)->not->toBeNull()->and($p->headline)->not->toBeNull()
        ->and($p->public_consent_at)->not->toBeNull()->and($p->public_consent_version)->toBe(config('teacher_profile.consent_version'))
        ->and($p->show_on_homepage)->toBeTrue()->and($p->homepage_order)->toBe(3);

    $plain = vvT36Profile(User::factory()->teacher()->withPublicProfile()->create());
    expect($plain->show_on_homepage)->toBeFalse();
});

test('config teacher_profile: hang so (khong doc env), du khoa theo thiet ke', function () {
    expect(config('teacher_profile.homepage_max'))->toBe(6)
        ->and(config('teacher_profile.avatar_max_edge'))->toBe(800)
        ->and(config('teacher_profile.order_max'))->toBe(999)
        ->and(config('teacher_profile.consent_version'))->toBeString()->not->toBe('')
        ->and(config('teacher_profile.consent_text'))->toContain('Tôi đồng ý công khai ảnh, họ tên');

    expect(file_get_contents(config_path('teacher_profile.php')))->not->toContain('env(');
});

test('ConsentType co teacher_public_profile', function () {
    expect(ConsentType::TeacherPublicProfile->value)->toBe('teacher_public_profile');
});
