<?php

use Symfony\Component\Finder\Finder;

test('controllers khong bao gio dung $request->all() (S17)', function () {
    $finder = (new Finder)->files()->in(app_path('Http/Controllers'))->name('*.php');

    $offenders = [];

    foreach ($finder as $file) {
        // Bắt mọi biến thể gọi ->all() (ví dụ $request->all(), request()->all()),
        // vẫn cho phép ->validated()/->only()/->except() và Collection::all()
        // của các đối tượng khác không phải Request — service chỉ nhận validated().
        if (preg_match('/\$request\s*->\s*all\s*\(|request\(\)\s*->\s*all\s*\(/', $file->getContents())) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], 'Controller không được dùng $request->all() (S17): '.implode(', ', $offenders));
});
