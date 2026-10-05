<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

require_once __DIR__.'/helpers.php';

function vvLogin(array $payload, array $headers = [])
{
    return test()->postJson(vvApiUrl('/auth/login'), $payload, vvWebHeaders($headers));
}

function vvStudent(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'hs@example.com',
        'phone' => '0912345678',
        'password' => Hash::make('dung-mat-khau-1'),
    ], $attrs));
}

test('AC3 dang nhap bang email (khong phan biet hoa thuong) hoac SDT (nhieu dang) deu vao duoc', function (string $login) {
    $user = vvStudent();

    vvLogin(['login' => $login, 'password' => 'dung-mat-khau-1'])
        ->assertOk()
        ->assertJson(['id' => $user->id, 'name' => $user->name, 'role' => 'hoc_sinh'])
        ->assertJsonMissingPath('password');

    expect($user->fresh()->last_login_at)->not->toBeNull();
})->with(['hs@example.com', 'HS@Example.COM', '0912345678', '+84912345678', '091 234 5678']);

test('AC4/BR5 sai mat khau va tai khoan khong ton tai tra CUNG thong diep, cung status', function () {
    vvStudent();

    $wrongPassword = vvLogin(['login' => 'hs@example.com', 'password' => 'sai-mat-khau']);
    $unknown = vvLogin(['login' => 'khong-co@example.com', 'password' => 'sai-mat-khau']);
    $unknownPhone = vvLogin(['login' => '0999999999', 'password' => 'sai-mat-khau']);

    foreach ([$wrongPassword, $unknown, $unknownPhone] as $response) {
        $response->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonPath('errors.login.0', 'Thông tin đăng nhập hoặc mật khẩu không đúng.');
    }
    expect($unknown->json('errors'))->toBe($wrongPassword->json('errors'));
});

test('S20 tai khoan bi khoa: sai mat khau -> thong diep chung; dung mat khau -> 403 ACCOUNT_LOCKED', function () {
    vvStudent(['status' => 'locked']);

    vvLogin(['login' => 'hs@example.com', 'password' => 'sai'])
        ->assertStatus(422)
        ->assertJsonPath('errors.login.0', 'Thông tin đăng nhập hoặc mật khẩu không đúng.');

    vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'ACCOUNT_LOCKED');
});

test('WRONG_PORTAL: staff/GV dung mat khau o host api -> 403, sai mat khau -> thong diep chung', function (string $state) {
    User::factory()->{$state}()->create(['email' => 'staff@example.com', 'password' => Hash::make('dung-mat-khau-1')]);

    vvLogin(['login' => 'staff@example.com', 'password' => 'sai'])
        ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');

    vvLogin(['login' => 'staff@example.com', 'password' => 'dung-mat-khau-1'])
        ->assertStatus(403)->assertJsonPath('code', 'WRONG_PORTAL');

    $this->assertGuest('web');
})->with(['admin', 'pageManager', 'teacher']);

test('dang nhap thanh cong khong tao phien khi WRONG_PORTAL/LOCKED', function () {
    vvStudent(['status' => 'locked']);
    vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])->assertForbidden();
    $this->assertGuest('web');
});

test('response login co Cache-Control no-store (R5)', function () {
    vvStudent();
    $response = vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('thieu login/password -> 422 theo tung field', function () {
    vvLogin([])->assertStatus(422)->assertJsonValidationErrors(['login', 'password']);
    vvLogin(['login' => ['a'], 'password' => ['b']])->assertStatus(422);
});

test('SQL injection / chuoi la trong login khong gay loi 500', function () {
    vvStudent();
    vvLogin(['login' => "' OR 1=1 --", 'password' => 'x'])->assertStatus(422);
    vvLogin(['login' => "a@b' OR '1'='1", 'password' => 'x'])->assertStatus(422);
});

test('dang nhap tao session moi (chong session fixation) va co cookie vv_session', function () {
    vvStudent();

    $before = vvWebHeaders();
    $csrf = $this->getJson(vvApiUrl('/csrf-token'), $before);
    $oldId = session()->getId();

    $response = vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1']);
    $response->assertOk();

    expect(session()->getId())->not->toBe($oldId);
    $this->assertAuthenticated('web');

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'vv_session');
    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getDomain())->toBeNull();
});

test('da dang nhap thi login/register -> 403 FORBIDDEN (guest)', function () {
    vvStudent();
    vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])->assertOk();

    vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])
        ->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    vvRegister()->assertStatus(403);
});

describe('throttle 2 lop (S10)', function () {
    test('R1 dang nhap DUNG nhieu lan khong bi 429 (chi dem luot sai)', function () {
        vvStudent();

        for ($i = 0; $i < 15; $i++) {
            vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])->assertOk();
            vvResetClient();
        }
    });

    test('R1 dang nhap dung xoa bo dem sai cua tai khoan; 11 lan sai van 429 kem Retry-After', function () {
        vvStudent();

        for ($i = 0; $i < 9; $i++) {
            vvLogin(['login' => 'hs@example.com', 'password' => 'sai'])->assertStatus(422);
        }
        vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])->assertOk();
        vvResetClient();

        for ($i = 0; $i < 10; $i++) {
            vvLogin(['login' => 'hs@example.com', 'password' => 'sai'])->assertStatus(422);
        }
        $response = vvLogin(['login' => 'hs@example.com', 'password' => 'sai'])->assertStatus(429);
        expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
        $response->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    });

    test('11 IP khac nhau (X-Forwarded-For gia) cung 1 tai khoan -> request thu 11 la 429', function () {
        vvStudent();

        for ($i = 1; $i <= 10; $i++) {
            vvLogin(['login' => 'hs@example.com', 'password' => 'sai'], ['X-Forwarded-For' => "203.0.113.{$i}"])
                ->assertStatus(422);
        }

        vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'], ['X-Forwarded-For' => '203.0.113.99'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
        $this->assertGuest('web');
    });

    test('doi cach viet SDT/hoa thuong khong ne duoc gioi han theo tai khoan', function () {
        vvStudent();
        $variants = ['0912345678', '+84912345678', '84912345678', '091 234 5678', '0912.345.678'];

        for ($i = 0; $i < 10; $i++) {
            vvLogin(['login' => $variants[$i % 5], 'password' => 'sai'])->assertStatus(422);
        }

        vvLogin(['login' => '+84 912 345 678', 'password' => 'sai'])->assertStatus(429);

        // Email: hoa/thuong cung 1 khoa.
        for ($i = 0; $i < 10; $i++) {
            vvLogin(['login' => $i % 2 ? 'HS@EXAMPLE.COM' : 'hs@example.com', 'password' => 'sai'])->assertStatus(422);
        }
        vvLogin(['login' => 'Hs@Example.Com', 'password' => 'sai'])->assertStatus(429);
    });

    test('1 IP, nhieu tai khoan khac nhau: 50/gio roi 429 (X-Forwarded-For gia khong tron duoc)', function () {
        for ($i = 1; $i <= 50; $i++) {
            vvLogin(['login' => "nguoi{$i}@example.com", 'password' => 'sai'], ['X-Forwarded-For' => "198.51.100.{$i}"])
                ->assertStatus(422);
        }

        vvLogin(['login' => 'nguoi51@example.com', 'password' => 'sai'], ['X-Forwarded-For' => '198.51.100.200'])
            ->assertStatus(429);
    });
});

describe('logout', function () {
    test('dang xuat -> 204, phien bi huy; goi lai khi chua dang nhap -> 401', function () {
        vvStudent();
        vvLogin(['login' => 'hs@example.com', 'password' => 'dung-mat-khau-1'])->assertOk();

        $this->postJson(vvApiUrl('/auth/logout'), [], vvWebHeaders())->assertNoContent();
        $this->assertGuest('web');
        // Mỗi request thật là 1 process mới; trong test phải bỏ guard đã cache user.
        $this->app['auth']->forgetGuards();

        $this->postJson(vvApiUrl('/auth/logout'), [], vvWebHeaders())
            ->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    });

    test('logout khong co phien -> 401 UNAUTHENTICATED', function () {
        $this->postJson(vvApiUrl('/auth/logout'), [], vvWebHeaders())->assertStatus(401);
    });
});

test('login khong ton tai tren host admin-api', function () {
    $this->postJson('http://'.config('app.admin_api_host').'/api/v1/auth/login', ['login' => 'a@b.c', 'password' => 'x'], ['Origin' => config('app.admin_url')])
        ->assertNotFound();
});
