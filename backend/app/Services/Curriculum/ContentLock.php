<?php

namespace App\Services\Curriculum;

use App\Models\Chapter;
use App\Models\Course;
use App\Models\Lesson;

/**
 * Khoá hàng cho thao tác nội dung (chương/bài/thứ tự) — PHẢI gọi trong
 * `DB::transaction`. Thứ tự khoá cố định: course → chapter → lesson.
 *
 * Mỗi hàm đọc lại bản ghi bằng `lockForUpdate()` và ĐỐI CHIẾU quan hệ với
 * tham số route: route binding chạy trước khi khoá nên bản ghi có thể đã bị
 * xoá/chuyển chương bởi request song song → 404 (S5, không thao tác trên dữ
 * liệu cũ).
 */
final class ContentLock
{
    public static function course(Course $course): Course
    {
        $locked = Course::query()->whereKey($course->getKey())->lockForUpdate()->first();

        return $locked ?? abort(404);
    }

    public static function chapter(Course $course, Chapter $chapter): Chapter
    {
        $locked = Chapter::query()
            ->whereKey($chapter->getKey())
            ->where('course_id', $course->getKey())
            ->lockForUpdate()
            ->first();

        return $locked ?? abort(404);
    }

    public static function lesson(Chapter $chapter, Lesson $lesson): Lesson
    {
        $locked = Lesson::query()
            ->whereKey($lesson->getKey())
            ->where('chapter_id', $chapter->getKey())
            ->where('course_id', $chapter->course_id)
            ->lockForUpdate()
            ->first();

        return $locked ?? abort(404);
    }
}
