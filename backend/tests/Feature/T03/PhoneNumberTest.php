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
