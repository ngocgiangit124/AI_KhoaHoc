import { GRADE_LEVELS, PER_PAGE_OPTIONS, type CourseQuery, type PerPage } from "./types";

export const COURSES_PATH = "/quan-tri/khoa-hoc";
export const DEFAULT_PER_PAGE: PerPage = 25;
export const SEARCH_MAX_LENGTH = 100;

type Params = Pick<URLSearchParams, "get">;

function positiveInt(raw: string | null, max = 1_000_000_000): number | null {
  if (raw === null || !/^\d+$/.test(raw)) return null;
  const n = Number(raw);
  return Number.isSafeInteger(n) && n >= 1 && n <= max ? n : null;
}

/** Đọc bộ lọc từ URL; giá trị lạ rơi về mặc định (không gửi tham số sai lên API → tránh 422). */
export function parseCourseQuery(params: Params): CourseQuery {
  const q = (params.get("q") ?? "").trim().slice(0, SEARCH_MAX_LENGTH);
  const statusRaw = params.get("status");
  const status = statusRaw === "draft" || statusRaw === "published" || statusRaw === "unpublished" ? statusRaw : "";
  const grade = positiveInt(params.get("grade_level"));
  const gradeLevel = grade !== null && (GRADE_LEVELS as readonly number[]).includes(grade) ? grade : null;
  const per = Number(params.get("per_page"));
  const perPage = (PER_PAGE_OPTIONS as readonly number[]).includes(per) ? (per as PerPage) : DEFAULT_PER_PAGE;
  return {
    q,
    status,
    gradeLevel,
    subjectId: positiveInt(params.get("subject_id")),
    teacherId: positiveInt(params.get("teacher_id")),
    page: positiveInt(params.get("page"), 100_000) ?? 1,
    perPage,
  };
}

/** Query string cho URL trang: bỏ tham số mặc định để link gọn. */
export function courseQueryToSearch(query: CourseQuery): string {
  const p = new URLSearchParams();
  if (query.q) p.set("q", query.q);
  if (query.status) p.set("status", query.status);
  if (query.gradeLevel !== null) p.set("grade_level", String(query.gradeLevel));
  if (query.subjectId !== null) p.set("subject_id", String(query.subjectId));
  if (query.teacherId !== null) p.set("teacher_id", String(query.teacherId));
  if (query.perPage !== DEFAULT_PER_PAGE) p.set("per_page", String(query.perPage));
  if (query.page > 1) p.set("page", String(query.page));
  const s = p.toString();
  return s ? `?${s}` : "";
}

/** Query string gọi API; giáo viên không gửi `teacher_id` (API bỏ qua, nhưng không gửi cho gọn). */
export function courseQueryToApi(query: CourseQuery, opts: { isStaff: boolean }): string {
  const p = new URLSearchParams();
  if (query.q) p.set("q", query.q);
  if (query.status) p.set("status", query.status);
  if (query.gradeLevel !== null) p.set("grade_level", String(query.gradeLevel));
  if (query.subjectId !== null) p.set("subject_id", String(query.subjectId));
  if (opts.isStaff && query.teacherId !== null) p.set("teacher_id", String(query.teacherId));
  p.set("per_page", String(query.perPage));
  p.set("page", String(query.page));
  return p.toString();
}

export function hasCourseFilter(query: CourseQuery): boolean {
  return query.q !== "" || query.status !== "" || query.gradeLevel !== null || query.subjectId !== null || query.teacherId !== null;
}
