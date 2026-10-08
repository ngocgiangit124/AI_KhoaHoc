import { ApiError } from "@vitaminvui/api-client";

/** Lỗi tải `/me/courses*` → màn thông báo tương ứng. 401: hộp thoại phiên (SessionEndedGate) lo, màn chỉ giữ trạng thái chờ. */
export type MyLoadFailure = "session" | "not_owned" | "not_found" | "throttled" | "error";

export function classifyMyLoadError(err: unknown): MyLoadFailure {
  if (err instanceof ApiError) {
    if (err.status === 401) return "session";
    if (err.status === 403) return "not_owned";
    if (err.status === 404) return "not_found";
    if (err.status === 429) return "throttled";
  }
  return "error";
}

/** `?trang=` → số trang ≥ 1 (sai/thiếu → 1). */
export function parsePage(raw: string | string[] | undefined): number {
  const v = Array.isArray(raw) ? raw[0] : raw;
  return v && /^[1-9]\d{0,3}$/.test(v) ? Number(v) : 1;
}

/** Id khóa trong đường dẫn: số nguyên dương, tối đa 10 chữ số (như `/hoc/[course]`). */
export function parseCourseId(raw: string): number | null {
  return /^[1-9]\d{0,9}$/.test(raw) ? Number(raw) : null;
}
