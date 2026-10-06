import type { MyCoursesResponse, ProgressQuiz } from "./types";

/** GET /me/courses — 3 khóa đang học, 1 chờ duyệt, 1 bị từ chối. */
export const myCourses: MyCoursesResponse = {
  data: [
    {
      course: { id: 101, title: "Hình học 9: Đường tròn từ cơ bản đến nâng cao", slug: "hinh-hoc-9-duong-tron", grade_level: 9, thumbnail_url: null, is_published: true },
      enrollment: { id: 801, status: "active", activated_at: "2026-09-20T10:00:00+07:00", last_accessed_at: "2026-10-06T19:28:00+07:00" },
      progress: { percent: 37, completed_lessons: 6, total_lessons: 16, is_completed: false, has_content: true },
      resume_lesson_id: 307,
      best_quiz_score: 7.5,
    },
    {
      course: { id: 104, title: "Căn bậc hai, căn bậc ba: học chắc nền tảng", slug: "can-bac-hai-can-bac-ba", grade_level: 9, thumbnail_url: null, is_published: true },
      enrollment: { id: 802, status: "active", activated_at: "2026-09-02T09:00:00+07:00", last_accessed_at: "2026-10-01T21:10:00+07:00" },
      progress: { percent: 100, completed_lessons: 5, total_lessons: 5, is_completed: true, has_content: true },
      resume_lesson_id: 10401,
      best_quiz_score: 9,
    },
    {
      course: { id: 102, title: "Phương trình bậc hai và hệ thức Vi-ét", slug: "phuong-trinh-bac-hai-vi-et", grade_level: 9, thumbnail_url: null, is_published: false },
      enrollment: { id: 803, status: "active", activated_at: "2026-10-05T08:00:00+07:00", last_accessed_at: null },
      progress: { percent: 0, completed_lessons: 0, total_lessons: 5, is_completed: false, has_content: true },
      resume_lesson_id: 10201,
      best_quiz_score: null,
    },
  ],
  meta: { current_page: 1, per_page: 12, total: 3, last_page: 1 },
  pending: [
    {
      enrollment_id: 804,
      status: "pending_approval",
      course: { id: 105, title: "Số học 6: Số tự nhiên và phép chia hết", slug: "so-hoc-6-chia-het", grade_level: 6, thumbnail_url: null, is_published: true },
      requested_at: "2026-10-05T16:20:00+07:00",
    },
  ],
  rejected: [
    {
      enrollment_id: 790,
      status: "rejected",
      course: { id: 113, title: "Bồi dưỡng học sinh giỏi Toán 9", slug: "boi-duong-hsg-toan-9", grade_level: 9, thumbnail_url: null, is_published: true },
      requested_at: "2026-09-28T10:00:00+07:00",
      rejection_reason: "Khóa dành cho học sinh lớp chọn của trường, em đăng ký khóa nền tảng trước nhé.",
    },
  ],
};

/** GET /me/courses/101/progress — phần quizzes. */
export const progressQuizzes: ProgressQuiz[] = [
  { id: 501, title: "Trắc nghiệm: tiếp tuyến của đường tròn", chapter_id: null, lesson_id: 305, question_count: 6, attempted: true, attempts_count: 2, best_score: 8.33 },
  { id: 502, title: "Luyện tập tổng hợp chương 2", chapter_id: null, lesson_id: 307, question_count: 8, attempted: true, attempts_count: 1, best_score: 7.5 },
  { id: 503, title: "Đề kiểm tra 45 phút — chương Đường tròn", chapter_id: 204, lesson_id: null, question_count: 20, attempted: false, attempts_count: 0, best_score: null },
];
