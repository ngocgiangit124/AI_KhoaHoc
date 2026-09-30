<?php

use App\Models\Course;
use App\Models\Enrollment;

/**
 * `counters:recount` (tasks.md T14, data-model §7 "Bộ đếm denormalize").
 */
test('counters:recount sua lai enrollments_count bi lech', function () {
    $course = Course::factory()->published()->create(['enrollments_count' => 5]);
    Enrollment::factory()->count(2)->create(['course_id' => $course->id]);

    $this->artisan('counters:recount')->assertExitCode(0);

    expect($course->fresh()->enrollments_count)->toBe(2);
});

test('counters:recount khong dong den khoa da dung so', function () {
    $course = Course::factory()->published()->create(['enrollments_count' => 0]);

    $this->artisan('counters:recount')->assertExitCode(0);

    expect($course->fresh()->enrollments_count)->toBe(0);
});

test('counters:recount chi dem enrollment active, khong dem pending/rejected/revoked', function () {
    $course = Course::factory()->published()->create(['enrollments_count' => 9]);
    Enrollment::factory()->pendingApproval()->create(['course_id' => $course->id]);
    Enrollment::factory()->rejected()->create(['course_id' => $course->id]);
    Enrollment::factory()->revoked()->create(['course_id' => $course->id]);
    Enrollment::factory()->create(['course_id' => $course->id]); // active

    $this->artisan('counters:recount')->assertExitCode(0);

    expect($course->fresh()->enrollments_count)->toBe(1);
});
