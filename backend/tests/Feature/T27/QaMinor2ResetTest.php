<?php

use App\Enums\UserStatus;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/helpers.php';

// QA "Sửa lỗi nhỏ 2" T27-5: 5 ca lỗi mã ở reset phải cho response giống hệt nhau.

function vvResetSnapshot($r): array
{
    $h = collect($r->headers->all())->except(['date', 'set-cookie', 'x-request-id'])->all();
    ksort($h);

    return ['status' => $r->status(), 'body' => Arr::except($r->json(), 'request_id'), 'headers' => $h];
}

test('T27-5: reset - ma sai / het 5 luot / khong co ma / khong ton tai / bi khoa: response giong het nhau', function () {
    $sender = vvFakeOtp();
    $issue = function (string $email, string $phone) use ($sender): string {
        $user = User::factory()->create(['email' => $email, 'phone' => $phone, 'email_verified_at' => now()]);
        (new VvPwBrowser)->call('POST', '/auth/password/forgot', ['login' => $email, 'captcha_token' => 'ok'])->assertStatus(202);
        Cache::flush();

        return $sender->lastCode();
    };

    $codeWrong = $issue('sai@example.com', '0911000001');
    $codeExhausted = $issue('het@example.com', '0911000002');
    $issue('khoa@example.com', '0911000003');
    User::query()->where('email', 'khoa@example.com')->update(['status' => UserStatus::Locked->value]);
    User::factory()->create(['email' => 'khongma@example.com', 'phone' => '0911000004']);

    $bad = fn (string $c) => $c === '000000' ? '111111' : '000000';

    // Hết 5 lượt: gửi 5 lần sai (bỏ qua kết quả), lần sau là ca "hết lượt".
    for ($i = 0; $i < 5; $i++) {
        Cache::flush();
        vvReset($bad($codeExhausted), ['login' => 'het@example.com'])->assertStatus(422);
    }
    expect(OtpCode::query()->where('destination', 'het@example.com')->value('attempts'))->toBeGreaterThanOrEqual(5);

    $cases = [
        'ma_sai' => ['sai@example.com', $bad($codeWrong)],
        'het_luot' => ['het@example.com', $codeExhausted], // mã ĐÚNG nhưng đã hết lượt
        'khong_co_ma' => ['khongma@example.com', '123456'],
        'khong_ton_tai' => ['khongco@example.com', '123456'],
        'bi_khoa' => ['khoa@example.com', $sender->lastCode() ?? '123456'],
    ];

    $snap = [];
    foreach ($cases as $name => [$login, $code]) {
        Cache::flush();
        $snap[$name] = vvResetSnapshot(vvReset($code, ['login' => $login])->assertStatus(422));
    }

    foreach ($snap as $name => $s) {
        expect($s)->toBe($snap['khong_ton_tai'], "ca $name khac ca khong ton tai");
    }
    expect($snap['ma_sai']['body']['code'])->toBe('OTP_EXPIRED')
        ->and($snap['ma_sai']['body'])->toHaveKey('message')->toHaveKey('errors');
    // Không rò rỉ mật khẩu đã đổi: các tài khoản thật chưa bị đổi mật khẩu.
    expect(User::query()->where('email', 'het@example.com')->first()->password)->not->toBeEmpty();
});

test('T27-5: reset thanh cong bang ma dung (doi chung, de dam bao ca loi khong that bai oan)', function () {
    $sender = vvFakeOtp();
    vvPwStudent();
    (new VvPwBrowser)->call('POST', '/auth/password/forgot', ['login' => 'hs@example.com', 'captcha_token' => 'ok'])->assertStatus(202);
    Cache::flush();

    vvReset($sender->lastCode())->assertOk();
});
