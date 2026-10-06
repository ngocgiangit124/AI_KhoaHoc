<?php

/** Tiến trình con cho race test T36 (1 kết nối MySQL/tiến trình). Chỉ chạy trên DB `*_testing`. */

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\Staff\StaffAccountService;
use App\Services\Teachers\TeacherProfileService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! preg_match('/_testing(_[a-z])?$/', (string) DB::connection()->getDatabaseName())) {
    fwrite(STDERR, "REFUSE: khong phai DB test\n");
    exit(9);
}

$mode = $argv[1];
$args = array_slice($argv, 2);
$wait = function (string $t): void {
    while (microtime(true) < (float) $t) {
        usleep(200);
    }
};
$run = function (callable $fn): array {
    try {
        $fn();

        return ['result' => 'ok'];
    } catch (DomainException $e) {
        return ['result' => 'domain', 'code' => $e->code(), 'status' => $e->status()];
    } catch (Throwable $e) {
        return ['result' => 'error', 'class' => $e::class, 'msg' => substr($e->getMessage(), 0, 300)];
    }
};
$teacher = fn (): User => User::factory()->create([
    'email' => 'race-tp-'.uniqid('', true).'@example.test', 'role' => UserRole::Teacher, 'status' => UserStatus::Active,
]);

$out = match ($mode) {
    // setup <preEnabled> <candidates>: dòng đang bật sẵn + ứng viên (chưa có dòng hồ sơ) + 1 admin thao tác.
    'setup' => (function () use ($args, $teacher) {
        $ids = [];
        $pre = [];
        foreach (range(1, max(0, (int) $args[0])) as $i) {
            if ((int) $args[0] === 0) {
                break;
            }
            $u = $teacher();
            DB::table('teacher_profiles')->insert(['user_id' => $u->id, 'show_on_homepage' => true, 'created_at' => now(), 'updated_at' => now()]);
            $pre[] = $u->id;
        }
        $candidates = [];
        foreach (range(1, (int) $args[1]) as $i) {
            $candidates[] = $teacher()->id;
        }
        $admin = User::factory()->create(['email' => 'race-adm-'.uniqid('', true).'@example.test', 'role' => UserRole::Admin, 'status' => UserStatus::Active]);
        $baseEnabled = TeacherProfile::query()->where('show_on_homepage', true)->whereNotIn('user_id', $pre)->count();

        return ['pre' => $pre, 'candidates' => $candidates, 'admin' => $admin->id, 'base_enabled' => $baseEnabled];
    })(),
    // enable <admin> <target> <t>
    'enable' => (function () use ($args, $wait, $run) {
        [$admin, $target, $t] = $args;
        $wait($t);

        return $run(fn () => app(TeacherProfileService::class)->setHomepage(User::findOrFail($target), true, false, null));
    })(),
    // disable <admin> <target> <t>
    'disable' => (function () use ($args, $wait, $run) {
        [$admin, $target, $t] = $args;
        $wait($t);

        return $run(fn () => app(TeacherProfileService::class)->setHomepage(User::findOrFail($target), false, false, null));
    })(),
    // content <admin> <target> <t> <text>
    'content' => (function () use ($args, $wait, $run) {
        [$admin, $target, $t, $text] = $args;
        $wait($t);

        return $run(fn () => app(TeacherProfileService::class)->updateContent(User::findOrFail($target), ['bio' => $text], User::findOrFail($admin)));
    })(),
    // consent <target> <t>
    'consent' => (function () use ($args, $wait, $run) {
        [$target, $t] = $args;
        $wait($t);

        return $run(fn () => app(TeacherProfileService::class)->giveConsent(User::findOrFail($target), (string) config('teacher_profile.consent_version')));
    })(),
    // withdraw <target> <t>
    'withdraw' => (function () use ($args, $wait, $run) {
        [$target, $t] = $args;
        $wait($t);

        return $run(fn () => app(TeacherProfileService::class)->withdrawConsent(User::findOrFail($target)));
    })(),
    // role <target> <t>: đổi vai trò (giống StaffAccountService::changeRole) chạy song song với thao tác hồ sơ.
    'role' => (function () use ($args, $wait, $run) {
        [$admin, $target, $t] = $args;
        $wait($t);

        return $run(fn () => app(StaffAccountService::class)->changeRole(User::findOrFail($target), UserRole::PageManager, User::findOrFail($admin)));
    })(),
    // erase <target> <t>: ẩn danh hoá/xoá hồ sơ (T34) chạy song song với thao tác hồ sơ khác.
    'erase' => (function () use ($args, $wait, $run) {
        [$target, $t] = $args;
        $wait($t);

        return $run(fn () => app(TeacherProfileService::class)->erase(User::findOrFail($target)));
    })(),
    'state' => (function () use ($args) {
        return [
            'profile_rows' => TeacherProfile::query()->whereIn('user_id', $args)->count(),
            'profile_with_consent' => TeacherProfile::query()->whereIn('user_id', $args)->whereNotNull('public_consent_at')->count(),
            'enabled_total' => TeacherProfile::query()->where('show_on_homepage', true)->count(),
            'enabled_in' => TeacherProfile::query()->whereIn('user_id', $args)->where('show_on_homepage', true)->count(),
            'bios' => TeacherProfile::query()->whereIn('user_id', $args)->pluck('bio', 'user_id')->all(),
            'consents' => DB::table('consents')->whereIn('user_id', $args)->where('type', 'teacher_public_profile')->whereNull('revoked_at')->count(),
            'consent_rows' => DB::table('consents')->whereIn('user_id', $args)->where('type', 'teacher_public_profile')->count(),
        ];
    })(),
    // Không xoá audit_logs: trigger bất biến chặn DELETE dòng mới; DB test riêng nên không ảnh hưởng assert (đếm theo subject_id).
    'cleanup' => (function () use ($args) {
        DB::table('consents')->whereIn('user_id', $args)->delete();
        DB::table('teacher_profiles')->whereIn('user_id', $args)->delete();
        DB::table('users')->whereIn('id', $args)->delete();

        return ['ok' => true];
    })(),
    default => ['error' => 'mode?'],
};

echo json_encode($out);
