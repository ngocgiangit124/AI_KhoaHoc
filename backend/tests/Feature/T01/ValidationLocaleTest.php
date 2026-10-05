<?php

use Illuminate\Support\Facades\Validator;

test('locale mac dinh la vi: thong bao validation va ten truong bang tieng Viet', function () {
    expect(app()->getLocale())->toBe('vi');

    $errors = Validator::make(['phone' => 'x', 'email' => 'abc', 'title' => str_repeat('a', 300)], [
        'phone' => ['required', 'integer'],
        'email' => ['email'],
        'title' => ['max:255'],
        'name' => ['required'],
    ])->errors();

    expect($errors->first('phone'))->toBe('Số điện thoại phải là số nguyên.')
        ->and($errors->first('email'))->toBe('Email phải là địa chỉ email hợp lệ.')
        ->and($errors->first('title'))->toBe('Tiêu đề không được dài quá 255 ký tự.')
        ->and($errors->first('name'))->toBe('Vui lòng nhập tên.');
});
