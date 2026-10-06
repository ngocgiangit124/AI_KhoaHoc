/**
 * Kiểu dữ liệu mẫu cho bản xem trước v2 — đặt tên trường đúng như docs/architecture/api-contract.md
 * để nextjs-dev thay bằng dữ liệu thật mà không đổi component. Mỗi kiểu ghi rõ endpoint nguồn.
 */

/** GET /config/public (§2.1) — các khoá giao diện dùng. */
export interface PublicConfig {
  quiz_time_limit_enabled: boolean;
  otp: { ttl_minutes: number; resend_cooldown_seconds: number };
  grades: number[];
  captcha_site_key: string | null;
  policy_version: string;
  parent_consent_age: number;
  /** false: ẩn/khoá mọi lối mua khóa có phí (V2 thanh toán). */
  paid_checkout_enabled: boolean;
}

/** GET /subjects (§2.1). */
export interface Subject {
  id: number;
  name: string;
  slug: string;
}

/** Item GET /courses (§2.1). */
export interface CatalogCourse {
  id: number;
  title: string;
  slug: string;
  short_description: string | null;
  grade_level: number;
  price: number;
  is_free: boolean;
  thumbnail_url: string | null;
  enrollments_count: number;
  published_at: string;
  subjects: Subject[];
  teachers: Array<{ id: number; name: string }>;
}

export interface Paginated<T> {
  data: T[];
  meta: { current_page: number; per_page: number; total: number; last_page: number };
  links: { next: string | null; prev: string | null };
}

/** GET /courses/{slug} (§2.1, T10). `description` là HTML đã lọc (FE vẫn DOMPurify). */
export interface CourseDetail extends Omit<CatalogCourse, "teachers"> {
  description: string;
  teachers: Array<{ id: number; name: string; bio: string | null; avatar_url: string | null }>;
  lessons_count: number;
  total_duration_seconds: number;
  has_preview: boolean;
  outline: Array<{
    id: number;
    title: string;
    position: number;
    lessons: Array<{ id: number; title: string; position: number; duration_seconds: number; is_preview: boolean }>;
  }>;
}

/** GET /courses/{slug}/viewer-state (§2.1). "guest" = chưa đăng nhập (không gọi endpoint). */
export type ViewerState = "can_buy" | "in_cart" | "can_register_free" | "pending_approval" | "owned";

export type LessonStatus = "not_started" | "in_progress" | "completed";

export interface QuizSummary {
  id: number;
  title: string;
  time_limit_minutes: number | null;
  question_count: number;
}

/** GET /learn/courses/{course} (§2.4, T13). */
export interface LearnCourse {
  course: { id: number; title: string; slug: string };
  course_percent: number;
  resume_lesson_id: number | null;
  chapters: Array<{
    id: number;
    title: string;
    position: number;
    quizzes: QuizSummary[];
    lessons: Array<{
      id: number;
      title: string;
      position: number;
      is_preview: boolean;
      duration_seconds: number;
      video_ready: boolean;
      status: LessonStatus;
      quizzes: QuizSummary[];
    }>;
  }>;
}

/** GET /learn/lessons/{lesson} (§2.4, T13). */
export interface LessonShow {
  lesson: {
    id: number;
    course_id: number;
    chapter_id: number;
    chapter_title: string;
    title: string;
    position: number;
    is_preview: boolean;
    duration_seconds: number;
    video_ready: boolean;
  };
  course: { id: number; title: string; slug: string };
  can_track: boolean;
  prev: { id: number; title: string } | null;
  next: { id: number; title: string } | null;
  quizzes: QuizSummary[];
  progress: { status: LessonStatus; watched_seconds: number; last_position_seconds: number; completed_at: string | null } | null;
}

export interface QuizOption {
  id: number;
  position: number;
  content: string;
}

/** POST /learn/quizzes/{quiz}/attempts — AttemptInProgress (§2.4, T22). Không có đáp án đúng. */
export interface AttemptInProgress {
  id: number;
  quiz_id: number;
  status: "in_progress";
  started_at: string;
  expires_at: string | null;
  server_now: string;
  remaining_seconds: number | null;
  total_questions: number;
  answers: Record<string, number>;
  questions: Array<{ id: number; position: number; content: string; options: QuizOption[] }>;
}

/** POST /learn/quiz-attempts/{attempt}/submit — AttemptResult (§2.4, T22). */
export interface AttemptResult {
  id: number;
  quiz_id: number;
  status: "submitted";
  started_at: string;
  submitted_at: string;
  auto_submitted: boolean;
  server_now: string;
  total_questions: number;
  correct_count: number;
  unanswered_count: number;
  score: number;
  questions: Array<{
    id: number;
    position: number;
    content: string;
    explanation: string | null;
    selected_option_id: number | null;
    correct_option_id: number;
    is_correct: boolean;
    options: QuizOption[];
  }>;
}

export interface MyCourseRef {
  id: number;
  title: string;
  slug: string;
  grade_level: number;
  thumbnail_url: string | null;
  is_published: boolean;
}

/** GET /me/courses (§2.4, T23). */
export interface MyCoursesResponse {
  data: Array<{
    course: MyCourseRef;
    enrollment: { id: number; status: "active"; activated_at: string; last_accessed_at: string | null };
    progress: { percent: number; completed_lessons: number; total_lessons: number; is_completed: boolean; has_content: boolean };
    resume_lesson_id: number | null;
    best_quiz_score: number | null;
  }>;
  meta: { current_page: number; per_page: number; total: number; last_page: number };
  pending: Array<{ enrollment_id: number; status: "pending_approval"; course: MyCourseRef; requested_at: string }>;
  rejected: Array<{ enrollment_id: number; status: "rejected"; course: MyCourseRef; requested_at: string; rejection_reason: string | null }>;
}

/** GET /me/courses/{course}/progress — phần quizzes (§2.4, T23). */
export interface ProgressQuiz {
  id: number;
  title: string;
  chapter_id: number | null;
  lesson_id: number | null;
  question_count: number;
  attempted: boolean;
  attempts_count: number;
  best_score: number | null;
}
