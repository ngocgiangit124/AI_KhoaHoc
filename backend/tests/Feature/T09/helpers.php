<?php

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;

require_once __DIR__.'/../T08/helpers.php';

/** @return array{0: Course, 1: Chapter, 2: Lesson} khóa với 1 chương và 1 bài (position 1) */
function vvContentSet(): array
{
    $course = Course::factory()->create();
    $chapter = Chapter::factory()->for($course)->create(['position' => 1]);
    $lesson = Lesson::factory()->for($course)->for($chapter)->create(['position' => 1]);

    return [$course, $chapter, $lesson];
}

function vvLessonPath(Course $course, Chapter $chapter, ?Lesson $lesson = null): string
{
    return "/admin/courses/{$course->id}/chapters/{$chapter->id}/lessons".($lesson ? "/{$lesson->id}" : '');
}
