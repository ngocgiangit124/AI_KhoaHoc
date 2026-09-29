<?php

use App\Services\Payments\Support\StrictAmountParser;

it('chấp nhận số nguyên hợp lệ', function () {
    expect(StrictAmountParser::parse(100000))->toBe(100000)
        ->and(StrictAmountParser::parse('100000'))->toBe(100000)
        ->and(StrictAmountParser::parse('0'))->toBe(0)
        ->and(StrictAmountParser::parse(0))->toBe(0);
});

it('từ chối số tiền không nghiêm ngặt (S12.3)', function (mixed $value) {
    expect(StrictAmountParser::parse($value))->toBeNull();
})->with([
    'thập phân dạng chuỗi' => ['100000.0'],
    'ký hiệu khoa học' => ['1e5'],
    'số âm dạng chuỗi' => ['-1000'],
    'số âm dạng int' => [-1],
    'float' => [100000.0],
    'chuỗi rỗng' => [''],
    'không phải số' => ['abc'],
    'null' => [null],
    'bool' => [true],
    'có khoảng trắng' => [' 100000'],
    'mảng' => [[100000]],
]);
