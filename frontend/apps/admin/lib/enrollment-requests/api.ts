import { authFetch } from "@/lib/api";
import { listCourses } from "@/lib/courses/api";
import type { CourseListItem } from "@/lib/courses/types";
import { requestQueryToApi } from "./query";
import type { EnrollmentRequest, EnrollmentRequestPage, RequestQuery } from "./types";

const BASE = "/api/v1/admin/enrollment-requests";

export function listRequests(query: RequestQuery, signal?: AbortSignal): Promise<EnrollmentRequestPage> {
  return authFetch<EnrollmentRequestPage>(`${BASE}?${requestQueryToApi(query)}`, { signal });
}

/** Object đơn trả PHẲNG; vẫn chấp nhận `{data}` nếu backend bọc lại. */
function unwrap(raw: EnrollmentRequest | { data: EnrollmentRequest }): EnrollmentRequest {
  return "data" in raw && !("id" in raw) ? raw.data : (raw as EnrollmentRequest);
}

/** `approve` không nhận lý do (server bỏ qua). Lần 2 → 409 `ALREADY_PROCESSED`. */
export async function approveRequest(id: number): Promise<EnrollmentRequest> {
  return unwrap(await authFetch(`${BASE}/${id}/approve`, { method: "POST" }));
}

/** `reason` tuỳ chọn (≤ 1000, văn bản thuần); rỗng thì không gửi. */
export async function rejectRequest(id: number, reason: string): Promise<EnrollmentRequest> {
  const trimmed = reason.trim();
  return unwrap(
    await authFetch(`${BASE}/${id}/reject`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(trimmed ? { reason: trimmed } : {}),
    }),
  );
}

const COURSE_PAGES_MAX = 4;

/**
 * Khóa miễn phí để chọn ở bộ lọc (chỉ khóa miễn phí mới có yêu cầu). Dùng `GET /admin/courses` (giáo viên chỉ thấy
 * khóa mình); lấy tối đa 4 trang × 50 khóa, chưa có endpoint riêng cho ô chọn.
 */
export async function listFreeCourses(isStaff: boolean, signal?: AbortSignal): Promise<CourseListItem[]> {
  const out: CourseListItem[] = [];
  for (let page = 1; page <= COURSE_PAGES_MAX; page++) {
    const res = await listCourses(
      { q: "", status: "", gradeLevel: null, subjectId: null, teacherId: null, page, perPage: 50 },
      { isStaff, ...(signal ? { signal } : {}) },
    );
    out.push(...res.data.filter((c) => c.price === 0));
    if (page >= res.meta.last_page) break;
  }
  return out;
}
