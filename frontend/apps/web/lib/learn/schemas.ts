import { z } from "zod";

/** Hình dạng response của `/learn/*` (api-contract §2.4, T13). Parse để sai contract lộ ra ngay thay vì vỡ giữa chừng. */

const lessonStatus = z.enum(["not_started", "in_progress", "completed"]);
export type LessonStatus = z.infer<typeof lessonStatus>;

const quizSummary = z.object({
  id: z.number(),
  title: z.string(),
  time_limit_minutes: z.number().nullable(),
  question_count: z.number(),
});
export type QuizSummary = z.infer<typeof quizSummary>;

export const learnCourseSchema = z.object({
  course: z.object({ id: z.number(), title: z.string(), slug: z.string() }),
  course_percent: z.number(),
  resume_lesson_id: z.number().nullable(),
  chapters: z.array(
    z.object({
      id: z.number(),
      title: z.string(),
      position: z.number(),
      quizzes: z.array(quizSummary),
      lessons: z.array(
        z.object({
          id: z.number(),
          title: z.string(),
          position: z.number(),
          is_preview: z.boolean(),
          duration_seconds: z.number().nullable(),
          video_ready: z.boolean(),
          status: lessonStatus,
          quizzes: z.array(quizSummary),
        }),
      ),
    }),
  ),
});
export type LearnCourse = z.infer<typeof learnCourseSchema>;

export const lessonShowSchema = z.object({
  lesson: z.object({
    id: z.number(),
    course_id: z.number(),
    chapter_id: z.number(),
    chapter_title: z.string(),
    title: z.string(),
    position: z.number(),
    is_preview: z.boolean(),
    duration_seconds: z.number().nullable(),
    video_ready: z.boolean(),
  }),
  course: z.object({ id: z.number(), title: z.string(), slug: z.string() }),
  can_track: z.boolean(),
  prev: z.object({ id: z.number(), title: z.string() }).nullable(),
  next: z.object({ id: z.number(), title: z.string() }).nullable(),
  quizzes: z.array(quizSummary),
  progress: z
    .object({
      status: lessonStatus,
      watched_seconds: z.number(),
      last_position_seconds: z.number(),
      completed_at: z.string().nullable(),
    })
    .nullable(),
});
export type LessonShow = z.infer<typeof lessonShowSchema>;

export const playbackInfoSchema = z.object({
  kind: z.enum(["hls", "embed"]),
  url: z.string().min(1),
  /** ISO8601; `null` với `embed`. */
  expires_at: z.string().nullable(),
  resume_at_seconds: z.number(),
});
export type PlaybackInfo = z.infer<typeof playbackInfoSchema>;

export const heartbeatResultSchema = z.object({
  status: z.enum(["in_progress", "completed"]),
  completed: z.boolean(),
  course_percent: z.number(),
});
export type HeartbeatResult = z.infer<typeof heartbeatResultSchema>;
