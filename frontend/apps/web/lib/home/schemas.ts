import { z } from "zod";

/**
 * `GET /api/v1/home/teachers` (api-contract §2.9, US-020 T36): `{ data: [...] }`, tối đa 6 người.
 * `avatar_url` và `bio` luôn khác null ở endpoint này (chỉ giáo viên đã đồng ý công khai mới có mặt) — vẫn để
 * `nullable` để một bản ghi lệch contract không làm hỏng cả khu vực (thẻ tự rơi về chữ cái đầu / ẩn bio).
 */
export const homeTeacherSchema = z.object({
  id: z.number(),
  name: z.string(),
  headline: z.string().nullable().default(null),
  bio: z.string().nullable().default(null),
  avatar_url: z.string().nullable().default(null),
  grade_levels: z.array(z.number()).default([]),
  courses_count: z.number().default(0),
});
export type HomeTeacherRow = z.infer<typeof homeTeacherSchema>;

export const homeTeachersSchema = z.object({ data: z.array(homeTeacherSchema) });
