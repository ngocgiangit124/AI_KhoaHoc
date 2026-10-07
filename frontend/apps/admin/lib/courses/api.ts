import { authFetch } from "@/lib/api";
import { listSubjects } from "@/lib/subjects/api";
import { courseQueryToApi } from "./query";
import type { CourseDetail, CourseListItem, CoursePage, CourseQuery, IdName } from "./types";

const BASE = "/api/v1/admin/courses";
const JSON_HEADERS = { "Content-Type": "application/json" };

/** Object đơn trả PHẲNG (JsonResource::withoutWrapping); vẫn chấp nhận `{data}` nếu backend đổi. */
export function unwrapOne<T extends { id: number }>(raw: T | { data: T }): T {
  if (typeof raw === "object" && raw !== null && !("id" in raw) && "data" in raw) return raw.data;
  return raw as T;
}

/** `GET /admin/courses` (GV chỉ thấy khóa được gán — do server lọc). */
export function listCourses(query: CourseQuery, opts: { isStaff: boolean; signal?: AbortSignal }): Promise<CoursePage> {
  return authFetch<CoursePage>(`${BASE}?${courseQueryToApi(query, opts)}`, { signal: opts.signal });
}

export async function getCourse(id: number, signal?: AbortSignal): Promise<CourseDetail> {
  return unwrapOne(await authFetch<CourseDetail | { data: CourseDetail }>(`${BASE}/${id}`, { signal }));
}

/** `POST /admin/courses` (multipart; không tự đặt Content-Type để trình duyệt gắn boundary) → 201. */
export async function createCourse(form: FormData): Promise<CourseDetail> {
  return unwrapOne(await authFetch<CourseDetail | { data: CourseDetail }>(BASE, { method: "POST", body: form }));
}

/** Sửa: JSON `PUT`, hoặc multipart `POST` + `_method=PUT` khi có ảnh mới. */
export async function updateCourse(id: number, req: { kind: "json"; body: Record<string, unknown> } | { kind: "multipart"; form: FormData }): Promise<CourseDetail> {
  const raw =
    req.kind === "json"
      ? await authFetch<CourseDetail | { data: CourseDetail }>(`${BASE}/${id}`, { method: "PUT", headers: JSON_HEADERS, body: JSON.stringify(req.body) })
      : await authFetch<CourseDetail | { data: CourseDetail }>(`${BASE}/${id}`, { method: "POST", body: req.form });
  return unwrapOne(raw);
}

/** `DELETE` → 204; 409 `COURSE_HAS_ENROLLMENTS`. */
export async function deleteCourse(id: number): Promise<void> {
  await authFetch<void>(`${BASE}/${id}`, { method: "DELETE" });
}

/** `POST .../publish` → 200; 422 `COURSE_NOT_PUBLISHABLE`; 409 `ALREADY_PROCESSED`. */
export async function publishCourse(id: number): Promise<CourseDetail> {
  return unwrapOne(await authFetch<CourseDetail | { data: CourseDetail }>(`${BASE}/${id}/publish`, { method: "POST" }));
}

/** `POST .../unpublish` → 200; 409 `INVALID_COURSE_STATE` (đang nháp) / `ALREADY_PROCESSED`. */
export async function unpublishCourse(id: number): Promise<CourseDetail> {
  return unwrapOne(await authFetch<CourseDetail | { data: CourseDetail }>(`${BASE}/${id}/unpublish`, { method: "POST" }));
}

/** `PATCH .../manual-order` body `{manual_order: int|null}` (bắt buộc có khoá). */
export async function setManualOrder(id: number, manualOrder: number | null): Promise<CourseListItem> {
  return unwrapOne(
    await authFetch<CourseListItem | { data: CourseListItem }>(`${BASE}/${id}/manual-order`, {
      method: "PATCH",
      headers: JSON_HEADERS,
      body: JSON.stringify({ manual_order: manualOrder }),
    }),
  );
}

/** `PUT .../teachers` `{teacher_ids[]}` (staff). */
export async function setCourseTeachers(id: number, teacherIds: number[]): Promise<CourseDetail> {
  return unwrapOne(
    await authFetch<CourseDetail | { data: CourseDetail }>(`${BASE}/${id}/teachers`, {
      method: "PUT",
      headers: JSON_HEADERS,
      body: JSON.stringify({ teacher_ids: teacherIds }),
    }),
  );
}

/** `GET /admin/teachers` (staff; giáo viên đang hoạt động, tối đa 500, chỉ id + name). */
export async function listTeachers(signal?: AbortSignal): Promise<IdName[]> {
  const res = await authFetch<{ data: IdName[] }>("/api/v1/admin/teachers", { signal });
  return res.data;
}

const MAX_SUBJECT_PAGES = 20;

/** Mọi chuyên đề đang hiển thị (API phân trang tối đa 50/trang) để làm ô chọn/lọc. */
export async function listActiveSubjects(opts: { isStaff: boolean; signal?: AbortSignal }): Promise<IdName[]> {
  const out: IdName[] = [];
  for (let page = 1; page <= MAX_SUBJECT_PAGES; page++) {
    const res = await listSubjects({ q: "", status: "active", page, perPage: 50 }, { includeStatus: opts.isStaff, signal: opts.signal });
    out.push(...res.data.filter((s) => s.status === "active").map((s) => ({ id: s.id, name: s.name })));
    if (page >= res.meta.last_page) break;
  }
  return out;
}
