import { z } from "zod";

/** Hình dạng response của `/me/courses*` (api-contract §2.4, T23). Parse để sai contract lộ ra ngay. */

/** Điểm là DECIMAL ở DB: có thể là chuỗi "7.50" — chấp nhận cả hai, ra số. */
const decimal = z.preprocess((v) => (typeof v === "string" && v.trim() !== "" ? Number(v) : v), z.number());

const courseRef = z.object({
  id: z.number(),
  title: z.string(),
  slug: z.string(),
  grade_level: z.number(),
  thumbnail_url: z.string().nullable(),
  is_published: z.boolean(),
});
export type MyCourseRef = z.infer<typeof courseRef>;

const progress = z.object({
  percent: z.number(),
  completed_lessons: z.number(),
  total_lessons: z.number(),
  is_completed: z.boolean(),
  has_content: z.boolean(),
});
export type MyProgress = z.infer<typeof progress>;

const myCourseItem = z.object({
  course: courseRef,
  enrollment: z.object({ id: z.number(), status: z.string(), activated_at: z.string().nullable(), last_accessed_at: z.string().nullable() }),
  progress,
  resume_lesson_id: z.number().nullable(),
  best_quiz_score: decimal.nullable(),
});
export type MyCourseItem = z.infer<typeof myCourseItem>;

export const myCoursesSchema = z.object({
  data: z.array(myCourseItem),
  meta: z.object({ current_page: z.number(), per_page: z.number(), total: z.number(), last_page: z.number() }),
  pending: z.array(z.object({ enrollment_id: z.number(), status: z.string(), course: courseRef, requested_at: z.string() })),
  rejected: z.array(
    z.object({ enrollment_id: z.number(), status: z.string(), course: courseRef, requested_at: z.string(), rejection_reason: z.string().nullable() }),
  ),
});
export type MyCourses = z.infer<typeof myCoursesSchema>;
export type PendingEnrollment = MyCourses["pending"][number];
export type RejectedEnrollment = MyCourses["rejected"][number];

export const lessonStatusSchema = z.enum(["not_started", "in_progress", "completed"]);
export type MyLessonStatus = z.infer<typeof lessonStatusSchema>;

const progressQuiz = z.object({
  id: z.number(),
  title: z.string(),
  chapter_id: z.number().nullable(),
  lesson_id: z.number().nullable(),
  question_count: z.number(),
  attempted: z.boolean(),
  attempts_count: z.number(),
  best_score: decimal.nullable(),
});
export type ProgressQuiz = z.infer<typeof progressQuiz>;

export const courseProgressSchema = z.object({
  course: courseRef,
  enrollment: z.object({ activated_at: z.string().nullable(), last_accessed_at: z.string().nullable() }),
  progress,
  resume_lesson_id: z.number().nullable(),
  chapters: z.array(
    z.object({
      id: z.number(),
      title: z.string(),
      position: z.number(),
      completed_lessons: z.number(),
      total_lessons: z.number(),
      lessons: z.array(
        z.object({
          id: z.number(),
          title: z.string(),
          position: z.number(),
          duration_seconds: z.number().nullable(),
          status: lessonStatusSchema,
          watched_seconds: z.number(),
          completed_at: z.string().nullable(),
        }),
      ),
    }),
  ),
  quizzes: z.array(progressQuiz),
});
export type CourseProgress = z.infer<typeof courseProgressSchema>;
