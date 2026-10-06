<?php

require_once __DIR__.'/helpers.php';

test('T12-2: tao phien upload truyen kich thuoc da khai xuong nha cung cap lam tran max_bytes', function () {
    vvCourseActor();
    $fake = vvVideoFake();
    [$course, , $lesson] = vvContentSet();

    vvRequestUpload($course, $lesson, ['size' => 12345678])->assertCreated();

    expect($fake->lastMaxBytes)->toBe(12345678);
});
