import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { UNKNOWN_ERROR_MESSAGE } from "@/lib/auth/errors";

export type SubjectFormFailure = { nameError: string | null; banner: string | null };

/** Lỗi tạo/đổi tên: 422 → dưới field `name`; field khác/lỗi khác → banner (không nuốt lỗi). */
export function classifySubjectFormError(err: unknown): SubjectFormFailure {
  if (err instanceof NetworkError) return { nameError: null, banner: err.message };
  if (err instanceof ApiError) {
    if (err.status === 422 && err.errors) {
      const nameError = err.errors.name?.[0] ?? null;
      const others = Object.entries(err.errors)
        .filter(([field]) => field !== "name")
        .map(([, msgs]) => msgs[0])
        .filter((m): m is string => Boolean(m));
      return { nameError, banner: others.length > 0 ? others.join(" ") : nameError ? null : err.message || UNKNOWN_ERROR_MESSAGE };
    }
    if (err.status === 403) return { nameError: null, banner: "Bạn không có quyền thực hiện thao tác này." };
    if (err.status === 404) return { nameError: null, banner: "Chuyên đề không còn tồn tại. Vui lòng đóng và tải lại danh sách." };
    return { nameError: null, banner: err.message || UNKNOWN_ERROR_MESSAGE };
  }
  return { nameError: null, banner: UNKNOWN_ERROR_MESSAGE };
}

export function isSubjectInUse(err: unknown): boolean {
  return err instanceof ApiError && err.status === 409 && err.code === "SUBJECT_IN_USE";
}

export function isForbidden(err: unknown): boolean {
  return err instanceof ApiError && err.status === 403 && err.code === "FORBIDDEN";
}

/** Thông điệp chung cho lỗi thao tác (ẩn/hiện/xoá/tải danh sách). */
export function subjectActionError(err: unknown): string {
  if (err instanceof NetworkError) return err.message;
  if (err instanceof ApiError) {
    if (err.status === 403) return "Bạn không có quyền thực hiện thao tác này.";
    if (err.status === 404) return "Chuyên đề không còn tồn tại.";
    return err.message || UNKNOWN_ERROR_MESSAGE;
  }
  return UNKNOWN_ERROR_MESSAGE;
}
