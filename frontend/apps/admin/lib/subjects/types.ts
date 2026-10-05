import type { PaginatedResponse } from "@vitaminvui/api-client";

export type SubjectStatus = "active" | "hidden";

/** Chuyên đề từ `SubjectResource` (T06). `courses_count` chỉ có với staff (giáo viên không nhận). */
export interface Subject {
  id: number;
  name: string;
  slug: string;
  status: SubjectStatus;
  courses_count?: number;
  created_at: string | null;
  updated_at: string | null;
}

export type SubjectPage = PaginatedResponse<Subject>;

export const PER_PAGE_OPTIONS = [25, 50] as const;
export type PerPage = (typeof PER_PAGE_OPTIONS)[number];

/** Bộ lọc trên URL của `/quan-tri/chuyen-de`. */
export interface SubjectQuery {
  q: string;
  status: SubjectStatus | "";
  page: number;
  perPage: PerPage;
}
