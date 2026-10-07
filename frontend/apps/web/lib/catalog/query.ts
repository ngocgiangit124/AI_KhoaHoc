/**
 * Bộ lọc danh mục nằm trên URL (`searchParams`) — chia sẻ link/F5 không mất trạng thái.
 * Giá trị sai trên URL bị bỏ qua êm (không 404, không 422): chỉ `/lop-{grade}` sai mới 404.
 */

export const SORTS = ["newest", "popular", "featured"] as const;
export type CatalogSort = (typeof SORTS)[number];

export const SORT_LABELS: Record<CatalogSort, string> = {
  newest: "Mới nhất",
  popular: "Phổ biến nhất",
  featured: "Nổi bật",
};

export const GRADES = [6, 7, 8, 9, 10, 11, 12] as const;
export const MAX_SUBJECT_IDS = 20;
export const MAX_Q_LENGTH = 100;

export interface CatalogQuery {
  grade: number | null;
  subjectIds: number[];
  /** Lọc theo giáo viên (US-020 Q6: liên kết "Xem N khóa học" ở trang chủ); `null` = không lọc. */
  teacherId: number | null;
  q: string;
  sort: CatalogSort;
  page: number;
}

export type RawSearchParams = Record<string, string | string[] | undefined>;

function first(value: string | string[] | undefined): string | undefined {
  return Array.isArray(value) ? value[0] : value;
}

function many(value: string | string[] | undefined): string[] {
  if (value === undefined) return [];
  return Array.isArray(value) ? value : [value];
}

export function isValidGrade(value: number): boolean {
  return Number.isInteger(value) && value >= 6 && value <= 12;
}

/** `lop-9` -> 9; sai định dạng/ngoài 6–12 -> null (trang gọi `notFound()`). */
export function parseGradeSegment(segment: string): number | null {
  const match = /^lop-(\d{1,2})$/.exec(segment);
  if (!match) return null;
  if (match[1]!.length > 1 && match[1]!.startsWith("0")) return null;
  const grade = Number(match[1]);
  return isValidGrade(grade) ? grade : null;
}

export function parseCatalogQuery(params: RawSearchParams, fixedGrade: number | null = null): CatalogQuery {
  const gradeRaw = first(params.grade);
  const gradeParsed = gradeRaw !== undefined && /^\d{1,2}$/.test(gradeRaw) ? Number(gradeRaw) : null;
  const grade = fixedGrade ?? (gradeParsed !== null && isValidGrade(gradeParsed) ? gradeParsed : null);

  const subjectIds: number[] = [];
  for (const raw of many(params.subject_ids)) {
    if (!/^\d{1,9}$/.test(raw)) continue;
    const id = Number(raw);
    if (id > 0 && !subjectIds.includes(id)) subjectIds.push(id);
    if (subjectIds.length >= MAX_SUBJECT_IDS) break;
  }

  const teacherRaw = first(params.teacher_id);
  const teacherParsed = teacherRaw !== undefined && /^\d{1,9}$/.test(teacherRaw) ? Number(teacherRaw) : 0;
  const teacherId = teacherParsed >= 1 ? teacherParsed : null;

  const q = (first(params.q) ?? "").trim().slice(0, MAX_Q_LENGTH);

  const sortRaw = first(params.sort);
  const sort = SORTS.find((s) => s === sortRaw) ?? "newest";

  const pageRaw = first(params.page);
  const pageParsed = pageRaw !== undefined && /^\d{1,6}$/.test(pageRaw) ? Number(pageRaw) : 1;
  const page = pageParsed >= 1 ? pageParsed : 1;

  return { grade, subjectIds, teacherId, q, sort, page };
}

/** Query gửi Laravel (`subject_ids[]`, bỏ giá trị mặc định). */
export function toApiQueryString(query: CatalogQuery): string {
  const sp = new URLSearchParams();
  if (query.grade !== null) sp.set("grade", String(query.grade));
  for (const id of query.subjectIds) sp.append("subject_ids[]", String(id));
  if (query.teacherId !== null) sp.set("teacher_id", String(query.teacherId));
  if (query.q) sp.set("q", query.q);
  if (query.sort !== "newest") sp.set("sort", query.sort);
  if (query.page > 1) sp.set("page", String(query.page));
  return sp.toString();
}

/**
 * Query trên URL trang web. `omitGrade` = true ở `/lop-{grade}` (lớp nằm trong đường dẫn).
 * Đổi bộ lọc thì về trang 1 (gọi với `page: 1`).
 */
export function toPageHref(basePath: string, query: CatalogQuery, omitGrade: boolean): string {
  const sp = new URLSearchParams();
  if (!omitGrade && query.grade !== null) sp.set("grade", String(query.grade));
  for (const id of query.subjectIds) sp.append("subject_ids", String(id));
  if (query.teacherId !== null) sp.set("teacher_id", String(query.teacherId));
  if (query.q) sp.set("q", query.q);
  if (query.sort !== "newest") sp.set("sort", query.sort);
  if (query.page > 1) sp.set("page", String(query.page));
  const qs = sp.toString();
  return qs ? `${basePath}?${qs}` : basePath;
}

export function hasActiveFilters(query: CatalogQuery, omitGrade: boolean): boolean {
  return (
    (!omitGrade && query.grade !== null) || query.subjectIds.length > 0 || query.teacherId !== null || query.q !== ""
  );
}

/** Cửa sổ trang hiển thị: 1 … n-1 n n+1 … last. */
export function pageWindow(current: number, last: number): (number | "gap")[] {
  const pages = new Set<number>([1, last, current - 1, current, current + 1]);
  const sorted = [...pages].filter((p) => p >= 1 && p <= last).sort((a, b) => a - b);
  const out: (number | "gap")[] = [];
  sorted.forEach((p, i) => {
    if (i > 0 && p - sorted[i - 1]! > 1) out.push("gap");
    out.push(p);
  });
  return out;
}

/** Slug khóa học hợp lệ (chặn ký tự lạ trước khi ghép vào đường dẫn API). */
export function isValidCourseSlug(slug: string): boolean {
  return slug.length > 0 && slug.length <= 255 && /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug);
}
