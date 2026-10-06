<?php

require_once __DIR__.'/helpers.php';

test('L4 cum 2: ten chuong/bai/khoa chua bidi (U+202E) hoac zero-width (U+200B, U+FEFF) -> 422; ten binh thuong qua', function (string $bad) {
    vvCourseActor();
    [$course, $chapter, $lesson] = vvContentSet();
    $title = 'Toán '.$bad.'lớp 9';

    vvCourseJson('POST', "/admin/courses/{$course->id}/chapters", ['title' => $title])
        ->assertStatus(422)->assertJsonValidationErrors(['title']);
    vvCourseJson('PUT', "/admin/courses/{$course->id}/chapters/{$chapter->id}", ['title' => $title])
        ->assertStatus(422)->assertJsonValidationErrors(['title']);
    vvCourseJson('POST', vvLessonPath($course, $chapter), ['title' => $title, 'video_source' => 'none'])
        ->assertStatus(422)->assertJsonValidationErrors(['title']);
    vvCourseJson('PUT', "/admin/courses/{$course->id}", ['title' => $title])
        ->assertStatus(422)->assertJsonValidationErrors(['title']);

    vvCourseJson('POST', "/admin/courses/{$course->id}/chapters", ['title' => 'Toán lớp 9'])->assertCreated();
})->with(["\u{202E}", "\u{200B}", "\u{FEFF}", "\u{2066}", "\u{200D}"]);

test('R7: emoji ghep bang ZWJ (👨‍👩‍👧) duoc nhan; ZWJ dung le/giua chu bi chan', function () {
    vvCourseActor();
    [$course] = vvContentSet();
    $path = "/admin/courses/{$course->id}/chapters";

    vvCourseJson('POST', $path, ['title' => "Gia đình 👨\u{200D}👩\u{200D}👧"])->assertCreated();
    vvCourseJson('POST', $path, ['title' => "Tim 👩🏽\u{200D}🏫 cô giáo"])->assertCreated();
    vvCourseJson('POST', $path, ['title' => "Toán\u{200D}lớp"])->assertStatus(422);
    vvCourseJson('POST', $path, ['title' => "đầu \u{200D}👨"])->assertStatus(422);
    vvCourseJson('POST', $path, ['title' => "👨\u{200D} cuối"])->assertStatus(422);
});
