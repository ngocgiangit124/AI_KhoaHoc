import type { HomeTeacher } from "@vitaminvui/ui/v2";
import type { HomeTeacherRow } from "./schemas";

/** Số thẻ tối đa của khu vực (US-020 BR3); API đã giới hạn, đây là lớp phòng thủ. */
export const HOME_TEACHERS_MAX = 6;

/** Số khóa nổi bật trên trang chủ (US-019 BR3). */
export const FEATURED_COURSES_MAX = 4;

/** Alt ảnh giáo viên (US-020 BR7). */
export function teacherPhotoAlt(name: string): string {
  return `Ảnh thầy/cô ${name}`;
}

/**
 * Hàng API -> dữ liệu thẻ của `@vitaminvui/ui` (tên trường trong UI là `published_courses_count`, API là `courses_count`).
 * `bio`/`headline` chỉ là văn bản thuần: không bao giờ đi qua HTML; chuỗi rỗng/khoảng trắng coi như không có.
 */
export function toHomeTeacher(row: HomeTeacherRow): HomeTeacher {
  const bio = row.bio?.trim() ? row.bio.trim() : null;
  const headline = row.headline?.trim() ? row.headline.trim() : null;
  return {
    id: row.id,
    name: row.name,
    avatar_url: row.avatar_url?.trim() ? row.avatar_url : null,
    headline,
    grade_levels: row.grade_levels,
    published_courses_count: row.courses_count,
    bio,
  };
}

export function toHomeTeachers(rows: HomeTeacherRow[]): HomeTeacher[] {
  return rows.slice(0, HOME_TEACHERS_MAX).map(toHomeTeacher);
}
