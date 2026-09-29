<?php

use App\Support\DeviceId;
use Illuminate\Http\Request;

test('normalize chap nhan UUID hop le', function () {
    expect(DeviceId::normalize('550e8400-e29b-41d4-a716-446655440000'))
        ->toBe('550e8400-e29b-41d4-a716-446655440000');
});

test('normalize tra null cho null/khong phai chuoi/rong/sai dinh dang/qua dai', function () {
    expect(DeviceId::normalize(null))->toBeNull();
    expect(DeviceId::normalize(123))->toBeNull();
    expect(DeviceId::normalize(''))->toBeNull();
    expect(DeviceId::normalize('   '))->toBeNull();
    expect(DeviceId::normalize('khong-phai-uuid'))->toBeNull();
    expect(DeviceId::normalize(str_repeat('a', 100)))->toBeNull();
});

test('normalize cat khoang trang thua', function () {
    expect(DeviceId::normalize('  550e8400-e29b-41d4-a716-446655440000  '))
        ->toBe('550e8400-e29b-41d4-a716-446655440000');
});

test('fromRequest uu tien header X-Device-Id, roi moi toi body device_id', function () {
    $requestWithHeader = Request::create('/', 'POST', ['device_id' => '550e8400-e29b-41d4-a716-446655440001']);
    $requestWithHeader->headers->set('X-Device-Id', '550e8400-e29b-41d4-a716-446655440002');

    expect(DeviceId::fromRequest($requestWithHeader))->toBe('550e8400-e29b-41d4-a716-446655440002');

    $requestOnlyBody = Request::create('/', 'POST', ['device_id' => '550e8400-e29b-41d4-a716-446655440003']);

    expect(DeviceId::fromRequest($requestOnlyBody))->toBe('550e8400-e29b-41d4-a716-446655440003');
});

test('fromRequest tra null khi ca header lan body deu sai/thieu', function () {
    $request = Request::create('/', 'POST', ['device_id' => 'sai-dinh-dang']);
    $request->headers->set('X-Device-Id', 'cung-sai');

    expect(DeviceId::fromRequest($request))->toBeNull();
});
