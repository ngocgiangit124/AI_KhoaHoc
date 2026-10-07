import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { UNKNOWN_ERROR_MESSAGE } from "@/lib/auth/errors";
import type { FieldErrors } from "./form";
import { HOMEPAGE_MAX_DEFAULT } from "./types";

export const FORBIDDEN_MESSAGE = "Bạn không có quyền thực hiện thao tác này.";
export const NOT_FOUND_MESSAGE = "Không tìm thấy hồ sơ giáo viên này.";
export const NOT_TEACHER_MESSAGE = "Tài khoản này không còn là giáo viên nên không sửa nội dung hoặc bật hiển thị được. Bạn vẫn có thể tắt hiển thị hoặc xoá ảnh.";
export const TOO_LARGE_MESSAGE = "Ảnh quá lớn. Ảnh tối đa 2 MB.";
export const homepageLimitMessage = (max = HOMEPAGE_MAX_DEFAULT) => `Trang chủ chỉ hiển thị tối đa ${max} giáo viên. Hãy tắt bớt một người trước.`;

export const hasStatus = (err: unknown, status: number) => err instanceof ApiError && err.status === status;
export const isForbidden = (err: unknown) => hasStatus(err, 403);
export const isNotFound = (err: unknown) => hasStatus(err, 404);
export const isNotTeacher = (err: unknown) => hasStatus(err, 422) && (err as ApiError).code === "NOT_TEACHER";
export const isConsentVersionChanged = (err: unknown) => hasStatus(err, 409) && (err as ApiError).code === "CONSENT_VERSION_CHANGED";
export const isHomepageLimit = (err: unknown) => hasStatus(err, 409) && (err as ApiError).code === "TEACHER_HOMEPAGE_LIMIT";

export interface ProfileFailure {
  fields: FieldErrors;
  banner: string | null;
  /** 404: hồ sơ/tài khoản không còn → màn nên báo và cho quay lại danh sách. */
  gone: boolean;
}

/** Lỗi lưu hồ sơ/tải ảnh: 422 → dưới đúng ô; 403/404/413/mạng → banner. */
export function classifyProfileError(err: unknown, ctx: { hadFile?: boolean } = {}): ProfileFailure {
  const empty: ProfileFailure = { fields: {}, banner: null, gone: false };
  if (err instanceof NetworkError) {
    // Nginx trả 413 không kèm CORS nên trình duyệt báo lỗi mạng.
    return { ...empty, banner: ctx.hadFile ? `${err.message} Nếu bạn vừa chọn ảnh, hãy chắc rằng ảnh không quá 2 MB.` : err.message };
  }
  if (!(err instanceof ApiError)) return { ...empty, banner: UNKNOWN_ERROR_MESSAGE };
  if (err.status === 413) return { ...empty, fields: { avatar: TOO_LARGE_MESSAGE } };
  if (err.status === 403) return { ...empty, banner: FORBIDDEN_MESSAGE };
  if (err.status === 404) return { ...empty, banner: NOT_FOUND_MESSAGE, gone: true };
  if (err.code === "NOT_TEACHER") return { ...empty, banner: NOT_TEACHER_MESSAGE };
  if (err.status === 422 && err.errors) {
    const fields: FieldErrors = {};
    const others: string[] = [];
    for (const [key, msgs] of Object.entries(err.errors)) {
      const message = msgs[0];
      if (!message) continue;
      if (key === "headline" || key === "bio" || key === "avatar") fields[key] ??= message;
      else others.push(message);
    }
    const hasFields = Object.keys(fields).length > 0;
    return { ...empty, fields, banner: others.length > 0 ? others.join(" ") : hasFields ? null : err.message || UNKNOWN_ERROR_MESSAGE };
  }
  if (err.status === 429) return { ...empty, banner: "Bạn thao tác quá nhanh. Vui lòng đợi một lát rồi thử lại." };
  return { ...empty, banner: err.message || UNKNOWN_ERROR_MESSAGE };
}

/** Thông điệp chung cho lỗi bật/tắt/đặt thứ tự/tải danh sách. */
export function profileActionError(err: unknown): string {
  if (err instanceof NetworkError) return err.message;
  if (err instanceof ApiError) {
    if (err.status === 403) return FORBIDDEN_MESSAGE;
    if (err.status === 404) return `${NOT_FOUND_MESSAGE} Danh sách đã được tải lại.`;
    if (err.code === "TEACHER_HOMEPAGE_LIMIT") return homepageLimitMessage();
    if (err.code === "NOT_TEACHER") return "Người này không còn là giáo viên nên không bật hiển thị được (tắt hoặc đổi thứ tự vẫn được).";
    if (err.status === 429) return "Bạn thao tác quá nhanh. Vui lòng đợi một lát rồi thử lại.";
    return err.message || UNKNOWN_ERROR_MESSAGE;
  }
  return UNKNOWN_ERROR_MESSAGE;
}
