<?php

use App\Models\User;
use App\Services\Auth\StudentSessionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Pest\TestSuite;

require_once __DIR__.'/../T03/helpers.php';

/** QA bổ sung T05: "trình duyệt" giả riêng (cookie + device), độc lập với SingleSessionTest. */
final class VvQaBrowser
{
    public ?string $cookie = null;

    public string $device;

    public function __construct(?string $device = null)
    {
        $this->device = $device ?? (string) Str::uuid();
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

        $response = $test->json($method, vvApiUrl($path), $payload, vvWebHeaders(array_merge(['X-Device-Id' => $this->device], $headers)));
        $set = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        if ($set !== null) {
            $this->cookie = $response->getCookie(config('session.cookie'))?->getValue() ?? $this->cookie;
        }

        return $response;
    }

    /** @param array<string, mixed> $extra */
    public function login(string $login = 'qa@example.com', string $password = 'dung-mat-khau-1', array $extra = [])
    {
        return $this->call('POST', '/auth/login', array_merge(['login' => $login, 'password' => $password, 'device_id' => $this->device], $extra));
    }
}

function vvQaStudent(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'qa@example.com', 'phone' => '0912345678', 'password' => Hash::make('dung-mat-khau-1'),
    ], $attrs));
}

test('AC1/AC2: A -> B -> A dang nhap lai (ping-pong) -> B nhan SESSION_REPLACED, A hop le, luon dung 1 phien', function () {
    $user = vvQaStudent();
    $a = new VvQaBrowser;
    $b = new VvQaBrowser;

    $a->login()->assertOk();
    $b->login()->assertOk();
    $a->login()->assertOk();

    expect($user->fresh()->current_session_id)->toBe($a->cookie);
    $a->call('GET', '/auth/me')->assertOk();
    $b->call('GET', '/auth/me')->assertStatus(401)->assertJsonPath('code', 'SESSION_REPLACED');
});

test('AC1: 10 lan luan phien A/B -> sau moi lan dung 1 phien song, phien con lai 401', function () {
    $user = vvQaStudent();
    $a = new VvQaBrowser;
    $b = new VvQaBrowser;

    foreach (range(1, 10) as $i) {
        [$new, $old] = $i % 2 ? [$a, $b] : [$b, $a];
        $new->login()->assertOk();
        expect($user->fresh()->current_session_id)->toBe($new->cookie);
        $new->call('GET', '/auth/me')->assertOk();
        $old->call('GET', '/auth/me')->assertStatus(401);
    }
});

test('T05-2: tombstone bi xoa (cache:clear) -> thiet bi cu nhan UNAUTHENTICATED, khong lo phien, khong song lai', function () {
    vvQaStudent();
    $a = new VvQaBrowser;
    $b = new VvQaBrowser;
    $a->login()->assertOk();
    $oldCookie = $a->cookie;
    $b->login()->assertOk();

    Cache::forget(StudentSessionService::tombstoneKey($oldCookie));

    $a->call('GET', '/auth/me')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    $b->call('GET', '/auth/me')->assertOk();
});

test('tombstone co TTL = SESSION_LIFETIME va khong chua id tho / email', function () {
    vvQaStudent();
    $a = new VvQaBrowser;
    $b = new VvQaBrowser;
    $a->login()->assertOk();
    $old = $a->cookie;
    $b->login()->assertOk();

    $value = Cache::get(StudentSessionService::tombstoneKey($old));
    expect($value)->toHaveKeys(['reason', 'new_device_id', 'at'])
        ->and($value['new_device_id'])->toBe($b->device)
        ->and(json_encode($value))->not->toContain($old)->not->toContain('qa@example.com');
});

test('BR/biên: remember-me bi bo qua -> khong co cookie recaller, van 1 phien', function () {
    vvQaStudent();
    $a = new VvQaBrowser;
    $res = $a->login(extra: ['remember' => true, 'remember_me' => true]);

    $res->assertOk();
    expect(collect($res->headers->getCookies())->contains(fn ($c) => str_starts_with($c->getName(), 'remember_web')))->toBeFalse();
});

test('phan quyen: logout khong co phien -> 401; logout 2 lan -> lan 2 401', function () {
    vvQaStudent();
    (new VvQaBrowser)->call('POST', '/auth/logout')->assertStatus(401);

    $a = new VvQaBrowser;
    $a->login()->assertOk();
    $a->call('POST', '/auth/logout')->assertNoContent();
    $a->call('POST', '/auth/logout')->assertStatus(401);
});

test('AC3: A -> B -> B logout -> A login lai thanh cong, me() OK, B (cookie cu) 401', function () {
    $user = vvQaStudent();
    $a = new VvQaBrowser;
    $b = new VvQaBrowser;
    $a->login()->assertOk();
    $b->login()->assertOk();
    $b->call('POST', '/auth/logout')->assertNoContent();
    $a->login()->assertOk();

    expect($user->fresh()->current_session_id)->toBe($a->cookie);
    $a->call('GET', '/auth/me')->assertOk();
    $b->call('GET', '/auth/me')->assertStatus(401);
});

test('Khoa: hoc sinh bi khoa + sai mat khau -> khong lo trang thai khoa (van 422 chung), dung mat khau -> 403 ACCOUNT_LOCKED', function () {
    $user = vvQaStudent();
    $a = new VvQaBrowser;
    $a->login()->assertOk();
    $user->forceFill(['status' => 'locked'])->save();

    $a->login(password: 'sai-mat-khau-xx')->assertStatus(422);
    $a->login()->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_LOCKED');
});

test('Khoa: khoa tai khoan khong bind phien moi: current_session_id giu nguyen sau khi login bi 403', function () {
    $user = vvQaStudent();
    $a = new VvQaBrowser;
    $a->login()->assertOk();
    $before = $user->fresh()->current_session_id;
    $user->forceFill(['status' => 'locked'])->save();

    $b = new VvQaBrowser;
    $b->login()->assertStatus(403);
    expect($user->fresh()->current_session_id)->toBe($before);
});

test('Phan quyen: giao vien dang nhap o host hoc sinh bi tu choi, khong bind/khong dung current_session_id', function () {
    $teacher = User::factory()->teacher()->create([
        'email' => 'gv@example.com', 'password' => Hash::make('dung-mat-khau-1'),
    ]);
    $a = new VvQaBrowser;
    $res = $a->login('gv@example.com');

    expect($res->status())->toBeIn([401, 403, 422]);
    expect($teacher->fresh()->current_session_id)->toBeNull();
});

test('Dang ky khi cookie cu cua hoc sinh khac da bi thay the: phien moi duoc bind cho user moi, user cu khong bi anh huong', function () {
    $user = vvQaStudent();
    $a = new VvQaBrowser;
    $b = new VvQaBrowser;
    $a->login()->assertOk();
    $b->login()->assertOk();

    // trình duyệt A (phiên đã bị thay thế) đăng ký tài khoản mới
    $a->call('POST', '/auth/register', vvRegisterPayload(['device_id' => $a->device, 'phone' => '0987654321']))->assertStatus(201);

    expect($user->fresh()->current_session_id)->toBe($b->cookie);
    $b->call('GET', '/auth/me')->assertOk()->assertJsonPath('id', $user->id);
    $a->call('GET', '/auth/me')->assertOk()->assertJsonPath('email', 'an@example.com');
});

test('Phien cu da bi destroy that khoi store (khong chi middleware chan)', function () {
    vvQaStudent();
    $a = new VvQaBrowser;
    $b = new VvQaBrowser;
    $a->login()->assertOk();
    $old = $a->cookie;
    expect(Session::getHandler()->read($old))->not->toBe('');
    $b->login()->assertOk();

    expect(Session::getHandler()->read($old))->toBe('');
});

test('device_id hoa/thuong khac nhau: UUID in hoa duoc chuan hoa lowercase va so khop', function () {
    $user = vvQaStudent();
    $uuid = (string) Str::uuid();
    $a = new VvQaBrowser(strtoupper($uuid));
    $a->login()->assertOk();

    expect($user->fresh()->current_device_id)->toBe($uuid);

    $b = new VvQaBrowser;
    $b->login()->assertOk();
    $again = new VvQaBrowser($uuid);
    $again->cookie = $a->cookie;
    $again->call('GET', '/auth/me')->assertStatus(401)->assertJsonPath('code', 'SESSION_REPLACED');
});

test('Hoc sinh chua xac thuc van bind phien (is_verified khong lien quan toi 1 phien)', function () {
    $user = vvQaStudent(['email_verified_at' => null]);
    $a = new VvQaBrowser;
    $b = new VvQaBrowser;
    $a->login()->assertOk();
    $b->login()->assertOk();

    expect($user->fresh()->current_session_id)->toBe($b->cookie);
    $a->call('GET', '/auth/me')->assertStatus(401)->assertJsonPath('code', 'SESSION_REPLACED');
});

test('Response 401 SESSION_REPLACED/EXPIRED khong lo thong tin nhay cam va co request_id', function () {
    vvQaStudent();
    $a = new VvQaBrowser;
    $b = new VvQaBrowser;
    $a->login()->assertOk();
    $b->login()->assertOk();

    $res = $a->call('GET', '/auth/me');
    $res->assertStatus(401);
    $body = json_encode($res->json());
    expect($body)->not->toContain($b->cookie)->not->toContain($b->device)->not->toContain('qa@example.com');
});
