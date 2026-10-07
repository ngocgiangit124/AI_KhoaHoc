import { z } from "zod";

/**
 * Schema response danh mục công khai (api-contract §3: GET /subjects, /courses, /courses/{slug},
 * /courses/{slug}/viewer-state — "T10 chốt"). Parse lúc runtime để backend lệch contract thì
 * báo lỗi rõ ràng thay vì render sai/crash trắng.
 */

export const subjectSchema = z.object({
  id: z.number(),
  name: z.string(),
  slug: z.string(),
});
export type Subject = z.infer<typeof subjectSchema>;

export const subjectListSchema = z.object({ data: z.array(subjectSchema) });

const teacherBriefSchema = z.object({ id: z.number(), name: z.string() });

export const courseListItemSchema = z.object({
  id: z.number(),
  title: z.string(),
  slug: z.string(),
  short_description: z.string().nullable(),
  grade_level: z.number(),
  price: z.number(),
  is_free: z.boolean(),
  /** `StaticUrl::to` trả null khi khóa chưa có ảnh. */
  thumbnail_url: z.string().nullable(),
  enrollments_count: z.number(),
  published_at: z.string().nullable(),
  subjects: z.array(subjectSchema),
  teachers: z.array(teacherBriefSchema),
});
export type CourseListItem = z.infer<typeof courseListItemSchema>;

export const courseListSchema = z.object({
  data: z.array(courseListItemSchema),
  meta: z.object({
    current_page: z.number(),
    per_page: z.number(),
    total: z.number(),
    last_page: z.number(),
  }),
  // `links` tương đối, dựng từ Host của request (review T10 R4) — FE không dùng, chỉ dựa vào `meta`.
});
export type CourseList = z.infer<typeof courseListSchema>;

export const lessonOutlineSchema = z.object({
  id: z.number(),
  title: z.string(),
  position: z.number(),
  duration_seconds: z.number().nullable(),
  is_preview: z.boolean(),
});
export type LessonOutline = z.infer<typeof lessonOutlineSchema>;

export const chapterOutlineSchema = z.object({
  id: z.number(),
  title: z.string(),
  position: z.number(),
  lessons: z.array(lessonOutlineSchema),
});
export type ChapterOutline = z.infer<typeof chapterOutlineSchema>;

export const courseDetailSchema = courseListItemSchema.extend({
  /** HTML đã lọc ở server (HtmlSanitizer); FE vẫn lọc lại bằng DOMPurify trước khi render. */
  description: z.string().nullable(),
  teachers: z.array(
    z.object({
      id: z.number(),
      name: z.string(),
      bio: z.string().nullable(),
      avatar_url: z.string().nullable(),
    }),
  ),
  lessons_count: z.number(),
  total_duration_seconds: z.number(),
  has_preview: z.boolean(),
  outline: z.array(chapterOutlineSchema),
});
export type CourseDetail = z.infer<typeof courseDetailSchema>;

export const VIEWER_STATES = ["owned", "pending_approval", "can_register_free", "can_buy", "in_cart"] as const;
export type ViewerStateValue = (typeof VIEWER_STATES)[number];

export const viewerStateSchema = z.object({
  viewer_state: z.enum(VIEWER_STATES),
  resume_lesson_id: z.number().nullable(),
});
export type ViewerState = z.infer<typeof viewerStateSchema>;
