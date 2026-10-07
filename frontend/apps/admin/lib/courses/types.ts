import type { PaginatedResponse } from "@vitaminvui/api-client";

export type CourseStatus = "draft" | "published" | "unpublished";

export const COURSE_STATUS_LABELS: Record<CourseStatus, string> = {
  draft: "Nháp",
  published: "Đã xuất bản",
  unpublished: "Ngừng bán",
};

export const GRADE_LEVELS = [6, 7, 8, 9, 10, 11, 12] as const;
export const PER_PAGE_OPTIONS = [25, 50] as const;
export type PerPage = (typeof PER_PAGE_OPTIONS)[number];

export interface IdName {
  id: number;
  name: string;
}

/** Dòng trong `GET /admin/courses` (`CourseListResource`, T08). `manual_order` chỉ có với staff. */
export interface CourseListItem {
  id: number;
  title: string;
  slug: string;
  short_description: string | null;
  grade_level: number;
  price: number;
  thumbnail_url: string | null;
  status: CourseStatus;
  published_at: string | null;
  manual_order?: number | null;
  enrollments_count: number;
  subjects: { id: number; name: string; slug: string }[];
  teachers: IdName[];
  created_by: number | null;
  created_at: string | null;
  updated_at: string | null;
}

/** Quyền UI trả kèm `GET /admin/courses/{id}` (chỉ để ẩn/hiện; quyền thật do Policy). */
export interface CourseAbilities {
  update: boolean;
  delete: boolean;
  publish: boolean;
  manage_teachers: boolean;
  edit_price: boolean;
  edit_grade_level: boolean;
}

export interface CourseDetail extends CourseListItem {
  description: string;
  chapters_count?: number;
  lessons_count?: number;
  abilities: CourseAbilities;
}

export type CoursePage = PaginatedResponse<CourseListItem>;

/** Bộ lọc trên URL của `/quan-tri/khoa-hoc`. */
export interface CourseQuery {
  q: string;
  status: CourseStatus | "";
  gradeLevel: number | null;
  subjectId: number | null;
  teacherId: number | null;
  page: number;
  perPage: PerPage;
}
