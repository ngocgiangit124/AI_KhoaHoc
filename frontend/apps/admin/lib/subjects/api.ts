import { authFetch } from "@/lib/api";
import { subjectQueryToApi } from "./query";
import type { Subject, SubjectPage, SubjectQuery, SubjectStatus } from "./types";

const BASE = "/api/v1/admin/subjects";
const JSON_HEADERS = { "Content-Type": "application/json" };

/** `GET /admin/subjects` (phân trang 25/50; giáo viên chỉ nhận `active`, không có `courses_count`). */
export function listSubjects(query: SubjectQuery, opts: { includeStatus: boolean; signal?: AbortSignal }): Promise<SubjectPage> {
  return authFetch<SubjectPage>(`${BASE}?${subjectQueryToApi(query, opts)}`, { signal: opts.signal });
}

// Object đơn lẻ trả PHẲNG, không bọc `data` (api-contract §1.5; JsonResource::withoutWrapping()).

/** `POST /admin/subjects` → 201 Subject. */
export function createSubject(name: string): Promise<Subject> {
  return authFetch<Subject>(BASE, { method: "POST", headers: JSON_HEADERS, body: JSON.stringify({ name }) });
}

/** `PUT /admin/subjects/{id}` (đổi tên; slug giữ nguyên). */
export function renameSubject(id: number, name: string): Promise<Subject> {
  return authFetch<Subject>(`${BASE}/${id}`, { method: "PUT", headers: JSON_HEADERS, body: JSON.stringify({ name }) });
}

/** `PATCH /admin/subjects/{id}/status`. */
export function setSubjectStatus(id: number, status: SubjectStatus): Promise<Subject> {
  return authFetch<Subject>(`${BASE}/${id}/status`, { method: "PATCH", headers: JSON_HEADERS, body: JSON.stringify({ status }) });
}

/** `DELETE /admin/subjects/{id}` → 204; 409 `SUBJECT_IN_USE` nếu đang gán khóa học. */
export async function deleteSubject(id: number): Promise<void> {
  await authFetch<void>(`${BASE}/${id}`, { method: "DELETE" });
}
