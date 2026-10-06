<?php

use App\Console\Commands\UsersPurgeUnverifiedCommand;
use App\Enums\ParentConsentStatus;
use App\Enums\UserRole;
use App\Models\Consent;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\User;
use App\Services\Staff\StaffAccountService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Tester\CommandTester;

function vvAudit(string $createdAt): void
{
    DB::table('audit_logs')->insert(['action' => 'x.test', 'ip' => '1.2.3.4', 'created_at' => $createdAt]);
}

test('audit:purge xoá log quá 24 tháng, giữ log mới; --dry-run không xoá', function () {
    DB::table('audit_logs')->delete();
    vvAudit(now()->subMonths(25)->toDateTimeString());
    vvAudit(now()->subMonths(30)->toDateTimeString());
    vvAudit(now()->subMonths(23)->toDateTimeString());

    $this->artisan('audit:purge --dry-run')->assertSuccessful();
    expect(DB::table('audit_logs')->count())->toBe(3);

    config(['ops.purge_chunk' => 1]);
    $this->artisan('audit:purge')->assertSuccessful();
    expect(DB::table('audit_logs')->count())->toBe(1);
});

test('users:purge-unverified chỉ xoá học sinh chưa xác thực > 7 ngày, không có dữ liệu', function () {
    $old = now()->subDays(8);
    $mk = fn () => User::factory()->student()->unverified()->create(['created_at' => $old]);

    $target = $mk();
    Consent::factory()->create(['user_id' => $target->id]);
    $withOrder = $mk();
    Order::factory()->create(['user_id' => $withOrder->id]);
    $withEnrollment = $mk();
    Enrollment::factory()->create(['user_id' => $withEnrollment->id]);
    $recent = User::factory()->student()->unverified()->create(['created_at' => now()->subDays(2)]);
    $verified = User::factory()->student()->verified()->create(['created_at' => $old]);
    $teacher = User::factory()->teacher()->unverified()->create(['created_at' => $old]);

    $this->artisan('users:purge-unverified --dry-run')->assertSuccessful();
    expect(User::whereKey($target->id)->exists())->toBeTrue();

    $this->artisan('users:purge-unverified')->assertSuccessful();

    expect(User::whereKey($target->id)->exists())->toBeFalse()
        ->and(DB::table('consents')->where('user_id', $target->id)->exists())->toBeFalse();
    foreach ([$withOrder, $withEnrollment, $recent, $verified, $teacher] as $kept) {
        expect(User::whereKey($kept->id)->exists())->toBeTrue();
    }
});

test('2 lệnh dọn được lên lịch withoutOverlapping + onOneServer', function () {
    $events = collect(app(Schedule::class)->events());
    foreach (['audit:purge', 'users:purge-unverified'] as $cmd) {
        $e = $events->first(fn ($e) => str_contains($e->command, $cmd));
        expect($e)->not->toBeNull()->and($e->withoutOverlapping)->toBeTrue()->and($e->onOneServer)->toBeTrue();
    }
});

test('purgeBatch: user xác thực sau khi lô được chọn thì không bị xoá, consents còn nguyên', function () {
    $u = User::factory()->student()->unverified()->create(['created_at' => now()->subDays(8)]);
    Consent::factory()->create(['user_id' => $u->id]);
    $ids = [$u->id];
    $u->forceFill(['email_verified_at' => now()])->save(); // xác thực xen giữa

    $deleted = app(UsersPurgeUnverifiedCommand::class)->purgeBatch($ids, now()->subDays(7));

    expect($deleted)->toBe(0)->and(User::whereKey($u->id)->exists())->toBeTrue()
        ->and(DB::table('consents')->where('user_id', $u->id)->count())->toBe(1);
});

test('lô lỗi: thử từng user, user lỗi bị bỏ qua, lệnh trả FAILURE, các user khác vẫn bị xoá', function () {
    $mk = fn () => User::factory()->student()->unverified()->create(['created_at' => now()->subDays(8)]);
    [$a, $bad, $c] = [$mk(), $mk(), $mk()];

    $cmd = new class extends UsersPurgeUnverifiedCommand
    {
        public static int $badId = 0;

        public function purgeBatch(array $ids, DateTimeInterface $cutoff): int
        {
            if (in_array(self::$badId, $ids, true)) {
                throw new RuntimeException('boom');
            }

            return parent::purgeBatch($ids, $cutoff);
        }
    };
    $cmd::$badId = $bad->id;
    $cmd->setLaravel(app());
    $tester = new CommandTester($cmd);

    expect($tester->execute([]))->toBe(1)
        ->and(User::whereKey($bad->id)->exists())->toBeTrue()
        ->and(User::whereKey($a->id)->exists())->toBeFalse()
        ->and(User::whereKey($c->id)->exists())->toBeFalse();
});

test('--days / --months không phải số bị từ chối', function () {
    $this->artisan('users:purge-unverified --days=abc')->assertFailed();
    $this->artisan('audit:purge --months=abc')->assertFailed();
});

test('QA: user vừa được ghi danh sau khi lô được chọn thì không bị xoá, consents còn nguyên', function () {
    $u = User::factory()->student()->unverified()->create(['created_at' => now()->subDays(8)]);
    Consent::factory()->create(['user_id' => $u->id]);
    $ids = [$u->id];
    Enrollment::factory()->create(['user_id' => $u->id]); // ghi danh xen giữa

    $deleted = app(UsersPurgeUnverifiedCommand::class)->purgeBatch($ids, now()->subDays(7));

    expect($deleted)->toBe(0)->and(User::whereKey($u->id)->exists())->toBeTrue()
        ->and(DB::table('consents')->where('user_id', $u->id)->count())->toBe(1);
});

test('QA: user có nhiều dòng consents bị xoá sạch cùng user; consents của user khác không đụng tới', function () {
    $target = User::factory()->student()->unverified()->create(['created_at' => now()->subDays(8)]);
    Consent::factory()->count(3)->create(['user_id' => $target->id]);
    $other = User::factory()->student()->verified()->create(['created_at' => now()->subDays(8)]);
    Consent::factory()->count(2)->create(['user_id' => $other->id]);

    $this->artisan('users:purge-unverified')->assertSuccessful();

    expect(User::whereKey($target->id)->exists())->toBeFalse()
        ->and(DB::table('consents')->where('user_id', $target->id)->count())->toBe(0)
        ->and(DB::table('consents')->where('user_id', $other->id)->count())->toBe(2);
});

test('QA: số user > purge_chunk, xen kẽ user được giữ -> xoá hết ứng viên qua nhiều lô, giữ đúng user không đủ điều kiện', function () {
    config(['ops.purge_chunk' => 2, 'ops.purge_sleep_ms' => 0]);
    $old = now()->subDays(8);
    $targets = [];
    $kept = [];
    for ($i = 0; $i < 7; $i++) {
        $targets[] = User::factory()->student()->unverified()->create(['created_at' => $old])->id;
        if ($i % 3 === 1) {
            $k = User::factory()->student()->unverified()->create(['created_at' => $old]);
            Enrollment::factory()->create(['user_id' => $k->id]);
            $kept[] = $k->id;
        }
    }

    $this->artisan('users:purge-unverified')->expectsOutputToContain('7 tài khoản đã xoá')->assertSuccessful();

    expect(User::whereIn('id', $targets)->count())->toBe(0)->and(User::whereIn('id', $kept)->count())->toBe(count($kept));
});

test('QA: audit:purge nhiều lô (chunk nhỏ) xoá đủ, --dry-run khớp số xoá thật', function () {
    DB::table('audit_logs')->delete();
    config(['ops.purge_chunk' => 2, 'ops.purge_sleep_ms' => 0]);
    foreach (range(1, 5) as $i) {
        vvAudit(now()->subMonths(24)->subDays($i)->toDateTimeString());
    }
    vvAudit(now()->subMonths(24)->addDay()->toDateTimeString());

    $this->artisan('audit:purge --dry-run')->expectsOutputToContain('5 dòng sẽ xoá')->assertSuccessful();
    $this->artisan('audit:purge')->expectsOutputToContain('5 dòng đã xoá')->assertSuccessful();
    expect(DB::table('audit_logs')->count())->toBe(1);

    $this->artisan('audit:purge --months=0')->assertFailed();
});

test('QA: users:purge-unverified --dry-run khớp số xoá thật; học sinh parent_consent pending bị tính; --days=0 bị từ chối', function () {
    $old = now()->subDays(8);
    User::factory()->student()->unverified()->count(3)->create(['created_at' => $old]);
    User::factory()->student()->unverified()->create(['created_at' => $old, 'parent_consent_status' => ParentConsentStatus::Pending]);
    User::factory()->student()->unverified()->create(['created_at' => now()->subDays(6)]);

    $before = User::count();
    $this->artisan('users:purge-unverified --dry-run')->expectsOutputToContain('4 tài khoản sẽ xoá')->assertSuccessful();
    expect(User::count())->toBe($before);
    $this->artisan('users:purge-unverified')->expectsOutputToContain('4 tài khoản đã xoá')->assertSuccessful();
    expect(User::count())->toBe($before - 4);

    $this->artisan('users:purge-unverified --days=0')->assertFailed();
});

test('QA: không có luồng nhân viên tạo/đổi vai trò thành học sinh (không thể cấp quyền hộ học sinh chưa xác thực)', function () {
    $svc = app(StaffAccountService::class);
    $admin = User::factory()->admin()->create();

    expect(fn () => $svc->create($admin, 'HS Một', 'hs1@example.test', UserRole::Student))
        ->toThrow(ValidationException::class);
    $teacher = User::factory()->teacher()->create();
    expect(fn () => $svc->changeRole($teacher, UserRole::Student, $admin))
        ->toThrow(ValidationException::class);
});
