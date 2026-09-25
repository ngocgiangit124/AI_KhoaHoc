<?php

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Finder\Finder;

test('khong co Model nao dung $guarded = [] (S17)', function () {
    $finder = (new Finder)->files()->in(app_path('Models'))->name('*.php');

    $checked = 0;

    foreach ($finder as $file) {
        $class = 'App\\Models\\'.$file->getBasename('.php');

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        $checked++;

        /** @var Model $instance */
        $instance = $reflection->newInstanceWithoutConstructor();

        expect($instance->getGuarded())
            ->not->toBe([], "Model {$class} không được dùng \$guarded = [] (S17).");
    }

    expect($checked)->toBeGreaterThan(0);
});
