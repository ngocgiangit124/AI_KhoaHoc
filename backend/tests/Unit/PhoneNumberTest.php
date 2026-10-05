<?php

use App\Services\Auth\PhoneNumber;

test('chuan hoa cac dang SDT Viet Nam ve 0xxxxxxxxx', function (string $input) {
    expect(PhoneNumber::normalize($input))->toBe('0912345678');
})->with(['0912345678', '+84912345678', '84912345678', '091 234 5678', '0912.345.678', ' 0912345678 ']);

test('tu choi SDT khong hop le', function (mixed $input) {
    expect(PhoneNumber::normalize($input))->toBeNull();
})->with(['', '12345', '0212345678', '091234567', '09123456789', 'abc', '0912345678; DROP', '++84912345678', null, [['0912345678']], 12345]);
