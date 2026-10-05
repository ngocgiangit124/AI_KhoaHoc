<?php

use App\Models\User;
use App\Services\Auth\StudentSessionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Pest\TestSuite;

require_once __DIR__.'/../T03/helpers.php';

/**
 * "Trình duyệt" giả: giữ cookie `vv_session` riêng + device id riêng. Mỗi request mô phỏng 1 process
 * mới (guard/session in-process không dính sang request sau), chỉ cookie mang trạng thái.
 */
final class VvBrowser
{
    public ?string $cookie = null;

    public function __construct(public ?string $device = null)
    {
        $this->device ??= (string) Str::uuid();
    }

    /** @param array<string, mixed> $payload @param array<string, string> $headers */
    public function call(string $method, string $path, array $payload = [], array $headers = [])
    {
        $test = TestSuite::getInstance()->test;
        app('auth')->forgetGuards();
        $test->flushSession();
        $test->flushHeaders();
        (new ReflectionProperty($test, 'defaultCookies'))->setValue($test, []);

        $test->withCredentials();

        if ($this->cookie !== null) {
            $test->withCookie(config('session.cookie'), $this->cookie);
        }

        $headers = vvWebHeaders(array_merge($this->device !== null ? ['X-Device-Id' => $this->device] : [], $headers));
        $response = $test->json($method, vvApiUrl($path), $payload, $headers);

        $set = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        if ($set !== null) {
            $this->cookie = $response->getCookie(config('session.cookie'))?->getValue() ?? $this->cookie;
        }

        return $response;
    }

    public function login(string $login = 'hs@example.com', string $password = 'dung-mat-khau-1')
    {
        return $this->call('POST', '/auth/login', [
            'login' => $login, 'password' => $password, 'device_id' => $this->device,
        ]);
    }

    public function me()
    {
        return $this->call('GET', '/auth/me');
    }

    public function logout()
    {
        return $this->call('POST', '/auth/logout');
    }
}

function vvSessionStudent(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'hs@example.com',
        'phone' => '0912345678',
        'password' => Hash::make('dung-mat-khau-1'),
    ], $attrs));
}

test('bind: dang nhap luu current_session_id + current_device_id; session id khac cookie truoc', function () {
    $user = vvSessionStudent();
    $a = new VvBrowser;

    $a->login()->assertOk();

    $fresh = $user->fresh();
    expect($fresh->current_session_id)->toBe($a->cookie)
        ->and($fresh->current_device_id)->toBe($a->device)
        ->and($fresh->last_login_at)->not->toBeNull();
    $a->me()->assertOk()->assertJsonPath('id', $user->id);
});

test('kich ban 1: A dang nhap -> B dang nhap -> A nhan 401 SESSION_REPLACED, B van dung duoc', function () {
    vvSessionStudent();
    $a = new VvBrowser;
    $b = new VvBrowser;

    $a->login()->assertOk();
    $oldSession = $a->cookie;
    $b->login()->assertOk();

    // Phiên cũ bị xoá thật khỏi store (kiểm TRƯỚC khi A gọi lại) + có tombstone.
    expect(Session::getHandler()->read($oldSession))->toBe('')
        ->and(Cache::get(StudentSessionService::tombstoneKey($oldSession))['reason'])->toBe('replaced');

    $res = $a->me();
    $res->assertStatus(401)
        ->assertJsonPath('code', 'SESSION_REPLACED')
        ->assertJsonPath('message', 'Tài khoản của bạn đã đăng nhập ở thiết bị khác. Nếu không phải bạn, hãy đổi mật khẩu ngay.');

    $b->me()->assertOk();
});

test('kich ban 2: A -> B -> B dang xuat -> A goi /auth/me van 401 (khong song lai)', function () {
    $user = vvSessionStudent();
    $a = new VvBrowser;
    $b = new VvBrowser;

    $a->login()->assertOk();
    $b->login()->assertOk();
    $b->logout()->assertNoContent();

    expect($user->fresh()->current_session_id)->toBe(StudentSessionService::LOGGED_OUT);

    $a->me()->assertStatus(401);
    // Gọi lại lần nữa vẫn 401 (lần đầu không được "nhận nuôi" phiên).
    $a->me()->assertStatus(401);
    $b->me()->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
});

test('kich ban 2b: phien con trong store nhung khong khop current_session_id (destroy loi) van bi chan, khong nhan nuoi', function () {
    $user = vvSessionStudent();
    $a = new VvBrowser;
    $a->login()->assertOk();

    // Mô phỏng bước destroy lỗi: current_session_id đã chuyển sang id khác nhưng payload cũ còn trong store.
    $user->forceFill(['current_session_id' => 'logged_out'])->save();

    // Cùng device_id với phiên hiện hành -> SESSION_EXPIRED; khác thiết bị -> SESSION_REPLACED.
    $a->me()->assertStatus(401)->assertJsonPath('code', 'SESSION_EXPIRED');

    $c = new VvBrowser;
    $c->login()->assertOk();
    User::query()->whereKey($user->id)->update(['current_session_id' => 'logged_out', 'current_device_id' => (string) Str::uuid()]);
    $c->me()->assertStatus(401)->assertJsonPath('code', 'SESSION_REPLACED');

    // NULL cũng không được nhận nuôi.
    $b = new VvBrowser;
    $b->login()->assertOk();
    User::query()->whereKey($user->id)->update(['current_session_id' => null]);
    $b->me()->assertStatus(401);
});

test('kich ban 3: dang nhap 2 lan cung device_id -> SESSION_EXPIRED, khong bao thiet bi khac', function () {
    vvSessionStudent();
    $device = (string) Str::uuid();
    $first = new VvBrowser($device);
    $second = new VvBrowser($device);

    $first->login()->assertOk();
    $second->login()->assertOk();

    $first->me()->assertStatus(401)->assertJsonPath('code', 'SESSION_EXPIRED');
    $second->me()->assertOk();
});

test('kich ban 3b: cung trinh duyet bam login lan 2 khi cookie cu con song -> 200, bind phien moi, khong 403', function () {
    $user = vvSessionStudent();
    $browser = new VvBrowser;

    $browser->login()->assertOk();
    $first = $browser->cookie;
    $browser->login()->assertOk();

    expect($browser->cookie)->not->toBe($first)
        ->and($user->fresh()->current_session_id)->toBe($browser->cookie);
    $browser->me()->assertOk();

    // Cookie cũ (đồng thời) cùng thiết bị -> SESSION_EXPIRED
    $stale = new VvBrowser($browser->device);
    $stale->cookie = $first;
    $stale->me()->assertStatus(401)->assertJsonPath('code', 'SESSION_EXPIRED');
});

test('kich ban 4: doi mat khau (revoke) -> phien khac nhan SESSION_REVOKED; locked -> 403 ACCOUNT_LOCKED', function (string $reason, int $status, string $code) {
    $user = vvSessionStudent();
    $a = new VvBrowser;
    $a->login()->assertOk();

    app(StudentSessionService::class)->revoke($user->fresh(), $reason);

    expect($user->fresh()->current_session_id)->toBe(StudentSessionService::LOGGED_OUT);
    $a->me()->assertStatus($status)->assertJsonPath('code', $code);
})->with([
    ['password_changed', 401, 'SESSION_REVOKED'],
    ['locked', 403, 'ACCOUNT_LOCKED'],
]);

test('kich ban 5: giao vien/admin/quan ly dang nhap 2 noi: middleware khong gioi han (current_session_id khong bi dung)', function (string $state) {
    $user = User::factory()->{$state}()->create();

    foreach (['a', 'b'] as $_) {
        app('auth')->forgetGuards();
        $this->actingAs($user);
        Route::middleware(['web', 'auth', 'student.single_session'])
            ->get('/__test/ss-'.$_, fn () => response()->json(['ok' => true]));
        $this->getJson('/__test/ss-'.$_)->assertOk();
    }
    expect($user->fresh()->current_session_id)->toBeNull();
})->with(['teacher', 'admin', 'pageManager']);

test('kich ban 6: X-Device-Id sai dinh dang bi bo qua (khong 422, khong ghi vao current_device_id)', function (mixed $bad) {
    $user = vvSessionStudent();
    $a = new VvBrowser;
    $a->device = null;
    $a->device = 'x';

    $res = $a->call('POST', '/auth/login', [
        'login' => 'hs@example.com', 'password' => 'dung-mat-khau-1', 'device_id' => $bad,
    ], ['X-Device-Id' => is_string($bad) ? $bad : 'zzz']);

    $res->assertOk();
    expect($user->fresh()->current_device_id)->toBeNull();
})->with([
    'khong phai uuid' => ['not-a-uuid'],
    'qua dai' => [str_repeat('a', 200)],
]);

test('device_id dang mang -> 422 (chi nhan chuoi)', function () {
    vvSessionStudent();
    (new VvBrowser)->call('POST', '/auth/login', [
        'login' => 'hs@example.com', 'password' => 'dung-mat-khau-1', 'device_id' => ['x'],
    ])->assertStatus(422)->assertJsonValidationErrors('device_id');
});

test('device_id trong query/body o request khac login/register khong duoc dung de chon thong diep', function () {
    vvSessionStudent();
    $a = new VvBrowser;
    $b = new VvBrowser;
    $a->login()->assertOk();
    $b->login()->assertOk();

    // A gửi device_id của B qua query: không được coi là cùng thiết bị.
    $a->call('GET', '/auth/me?device_id='.$b->device)->assertStatus(401)->assertJsonPath('code', 'SESSION_REPLACED');
});

test('R1 cung trinh duyet dang giu phien hop le dang nhap lai + DB loi khi bind -> cookie cu van 200', function () {
    $user = vvSessionStudent();
    $a = new VvBrowser;
    $a->login()->assertOk();
    $oldCookie = $a->cookie;

    User::updating(function (User $u): bool {
        if ($u->isDirty('current_session_id')) {
            throw new RuntimeException('db down');
        }

        return true;
    });

    $a->login()->assertStatus(500);
    User::flushEventListeners();

    expect($user->fresh()->current_session_id)->toBe($oldCookie);
    $a->cookie = $oldCookie;
    $a->me()->assertOk();
});

test('R2 dang ky: bind phien loi -> van 201, tai khoan ton tai, khong co phien', function () {
    User::updating(function (User $u): bool {
        if ($u->isDirty('current_session_id')) {
            throw new RuntimeException('db down');
        }

        return true;
    });

    $a = new VvBrowser;
    $a->call('POST', '/auth/register', vvRegisterPayload())
        ->assertStatus(201)->assertJsonPath('email', 'an@example.com');
    User::flushEventListeners();

    $user = User::where('email', 'an@example.com')->firstOrFail();
    expect($user->current_session_id)->toBeNull();
    $a->me()->assertStatus(401);
});

test('X-Device-Id sai dinh dang khong the gia mao SESSION_EXPIRED', function () {
    vvSessionStudent();
    $a = new VvBrowser;
    $b = new VvBrowser;
    $a->login()->assertOk();
    $b->login()->assertOk();

    $a->call('GET', '/auth/me', [], ['X-Device-Id' => 'zzz'])->assertJsonPath('code', 'SESSION_REPLACED');
    expect(StudentSessionService::sanitizeDeviceId('ZZZ'))->toBeNull()
        ->and(StudentSessionService::sanitizeDeviceId((string) Str::uuid()))->not->toBeNull();
});

test('AC3 dang xuat roi dang nhap lai tren cung thiet bi khong anh huong phien khac', function () {
    $user = vvSessionStudent();
    $a = new VvBrowser;

    $a->login()->assertOk();
    $a->logout()->assertNoContent();
    expect($user->fresh()->current_session_id)->toBe(StudentSessionService::LOGGED_OUT);

    $a->login()->assertOk();
    $a->me()->assertOk();
});

test('dang xuat tu phien da bi thay the khong huy phien hien hanh cua thiet bi moi', function () {
    $user = vvSessionStudent();
    $a = new VvBrowser;
    $b = new VvBrowser;
    $a->login()->assertOk();
    $stale = $a->cookie;
    $b->login()->assertOk();

    $ghost = new VvBrowser;
    $ghost->cookie = $stale;
    $ghost->logout();

    expect($user->fresh()->current_session_id)->toBe($b->cookie);
    $b->me()->assertOk();
});

test('AC5 mat mang tam thoi: nhieu request lien tiep tren phien hien hanh van hop le', function () {
    vvSessionStudent();
    $a = new VvBrowser;
    $a->login()->assertOk();

    foreach (range(1, 3) as $_) {
        $a->me()->assertOk();
    }
});

test('diem treo 2: nguoi dung bi khoa sau khi co session goi login -> ACCOUNT_LOCKED, khong FORBIDDEN', function () {
    $user = vvSessionStudent();
    $a = new VvBrowser;
    $a->login()->assertOk();

    $user->forceFill(['status' => 'locked'])->save();

    $a->login()->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_LOCKED');
    // và request thường cũng ACCOUNT_LOCKED (account.active)
    $a->me()->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_LOCKED');
});

test('diem treo 1: register khi dang dang nhap hop le -> 403 FORBIDDEN; khi phien da bi thay the -> coi nhu khach', function () {
    vvSessionStudent();
    $a = new VvBrowser;
    $a->login()->assertOk();

    $a->call('POST', '/auth/register', vvRegisterPayload(['email' => 'moi@example.com', 'phone' => '0987654321']))
        ->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');

    // Phiên của a còn trong store nhưng không còn là phiên hiện hành -> không FORBIDDEN.
    User::query()->where('email', 'hs@example.com')->update(['current_session_id' => 'logged_out']);
    $a->call('POST', '/auth/register', vvRegisterPayload(['email' => 'moi@example.com', 'phone' => '0987654321']))
        ->assertStatus(201);
});

test('dang ky bind phien: sau register /auth/me hoat dong va current_session_id khop', function () {
    $a = new VvBrowser;
    $res = $a->call('POST', '/auth/register', vvRegisterPayload(['device_id' => $a->device]));
    $res->assertStatus(201);

    $user = User::where('email', 'an@example.com')->firstOrFail();
    expect($user->current_session_id)->toBe($a->cookie)->and($user->current_device_id)->toBe($a->device);
    $a->me()->assertOk();
});

test('dang nhap B khi trinh duyet dang giu phien cua hoc sinh khac A: phien A duoc nha (logged_out)', function () {
    $userA = vvSessionStudent();
    $userB = vvSessionStudent(['email' => 'b@example.com', 'phone' => '0911000111']);
    $browser = new VvBrowser;
    $browser->login()->assertOk();
    $oldCookie = $browser->cookie;

    $browser->login('b@example.com')->assertOk();

    expect($userA->fresh()->current_session_id)->toBe(StudentSessionService::LOGGED_OUT)
        ->and($userB->fresh()->current_session_id)->toBe($browser->cookie);
    $ghost = new VvBrowser;
    $ghost->cookie = $oldCookie;
    $ghost->me()->assertStatus(401);
});

test('loi ghi DB khi bind: dang xuat phien moi, phien cu van la phien duy nhat, tra 500', function () {
    $user = vvSessionStudent();
    $a = new VvBrowser;
    $a->login()->assertOk();
    $oldSession = $a->cookie;

    User::updating(function (User $u): bool {
        if ($u->isDirty('current_session_id')) {
            throw new RuntimeException('db down');
        }

        return true;
    });

    $b = new VvBrowser;
    $b->login()->assertStatus(500);
    User::flushEventListeners();

    expect($user->fresh()->current_session_id)->toBe($oldSession);
    $a->me()->assertOk();
    $b->me()->assertStatus(401);
});

test('tombstone chi luu hash sha256 cua session id, khong luu id tho', function () {
    vvSessionStudent();
    $a = new VvBrowser;
    $b = new VvBrowser;
    $a->login()->assertOk();
    $b->login()->assertOk();

    expect(StudentSessionService::tombstoneKey($a->cookie))->toBe('session_replaced:'.hash('sha256', $a->cookie))
        ->and(StudentSessionService::tombstoneKey($a->cookie))->not->toContain($a->cookie);
});
