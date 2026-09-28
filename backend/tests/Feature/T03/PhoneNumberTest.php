<?php

use App\Services\Auth\PhoneNumber;

test('chuan hoa cac dang nhap +84/84/0xxx ve cung 1 gia tri', function () {
    expect(PhoneNumber::fromInput('0912345678')->value())->toBe('0912345678');
    expect(PhoneNumber::fromInput('+84912345678')->value())->toBe('0912345678');
    expect(PhoneNumber::fromInput('84912345678')->value())->toBe('0912345678');
    expect(PhoneNumber::fromInput(' 091 234 5678 ')->value())->toBe('0912345678');
});

test('tu choi dinh dang khong phai SDT di dong Viet Nam', function () {
    expect(PhoneNumber::isValidInput('0123456789'))->toBeFalse(); // đầu số 01x đã ngừng cấp
    expect(PhoneNumber::isValidInput('091234567'))->toBeFalse(); // thiếu 1 số
    expect(PhoneNumber::isValidInput('09123456789'))->toBeFalse(); // thừa 1 số
    expect(PhoneNumber::isValidInput('abcdefghij'))->toBeFalse();
    expect(PhoneNumber::isValidInput(''))->toBeFalse();
});

test('fromInput nem InvalidArgumentException khi sai dinh dang', function () {
    expect(fn () => PhoneNumber::fromInput('khong-phai-sdt'))->toThrow(InvalidArgumentException::class);
});

/**
 * R6 (docs/qa/review-T03-FW1.md) — US-001 chỉ nói "SĐT" cho đăng ký/đăng nhập
 * học sinh, phạm vi là SĐT DI ĐỘNG (đầu số 03/05/07/08/09 theo quy hoạch hiện
 * hành). Khoá rõ hành vi: số CỐ ĐỊNH (đầu 02x, có mã vùng) bị từ chối, phòng
 * khi sau này ai nới lỏng pattern mà không để ý phá quy tắc.
 */
test('so co dinh (dau 02x) bi tu choi', function () {
    expect(PhoneNumber::isValidInput('0281234567'))->toBeFalse(); // 028 = TP.HCM
    expect(PhoneNumber::isValidInput('0241234567'))->toBeFalse(); // 024 = Hà Nội
    expect(fn () => PhoneNumber::fromInput('0281234567'))->toThrow(InvalidArgumentException::class);
});
