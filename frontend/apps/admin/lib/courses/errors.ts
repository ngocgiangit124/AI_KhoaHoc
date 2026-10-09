import { ApiError, NetworkError, errorMessages } from "@vitaminvui/api-client";
import { UNKNOWN_ERROR_MESSAGE } from "@/lib/auth/errors";
import { THUMBNAIL_FORMAT_ERROR } from "./image";
import type { FieldErrors } from "./form";

export const NOT_PUBLISHABLE_MESSAGE = "Khóa học cần có ít nhất 1 chương và 1 bài học để xuất bản.";
export const COURSE_GONE_MESSAGE = "Khóa học không còn tồn tại.";
export const FORBIDDEN_MESSAGE = "Bạn không có quyền thực hiện thao tác này.";
export const TOO_LARGE_MESSAGE = "Tệp tải lên quá lớn. Ảnh bìa tối đa 2 MB.";

export interface CourseFormFailure {
  fields: FieldErrors;
  banner: string | null;
  /** Khóa học đã bị xoá từ nơi khác (404): màn hình nên báo và cho quay lại danh sách. */
  gone: boolean;
}

const KNOWN_FIELDS = ["title", "grade_level", "subject_ids", "short_description", "description", "price", "teacher_ids", "thumbnail"] as const;

/** `subject_ids.0` / `teacher_ids.2` → field cha (lỗi từng phần tử hiện dưới nhóm chọn). */
export function normalizeErrorKey(key: string): string {
  return key.replace(/\.\d+$/, "").replace(/\.\*$/, "");
}

/**
 * Lỗi lưu form: 422 → dưới đúng field (kể cả `subject_ids.N`, `teacher_ids`); field lạ → banner; 413 (Nginx trả HTML,
 * không phải JSON) → lỗi ở ô ảnh; 403/404/mạng → banner.
 */
export function classifyCourseFormError(err: unknown, ctx: { hadFile: boolean }): CourseFormFailure {
  const empty: CourseFormFailure = { fields: {}, banner: null, gone: false };
  if (err instanceof NetworkError) {
    // Nginx trả 413 không kèm header CORS nên trình duyệt báo lỗi mạng thay vì 413.
    return { ...empty, banner: ctx.hadFile ? `${err.message} Nếu bạn vừa chọn ảnh, hãy chắc rằng ảnh không quá 2 MB.` : err.message };
  }
  if (!(err instanceof ApiError)) return { ...empty, banner: UNKNOWN_ERROR_MESSAGE };
  if (err.status === 413) return { ...empty, fields: { thumbnail: TOO_LARGE_MESSAGE } };
  if (err.status === 403) return { ...empty, banner: FORBIDDEN_MESSAGE };
  if (err.status === 404) return { ...empty, banner: `${COURSE_GONE_MESSAGE} Vui lòng quay lại danh sách.`, gone: true };
  if (err.status === 422 && err.errors) {
    const fields: FieldErrors = {};
    const others: string[] = [];
    for (const [key, msgs] of Object.entries(errorMessages(err))) {
      const message = msgs[0];
      if (!message) continue;
      const field = normalizeErrorKey(key);
      if ((KNOWN_FIELDS as readonly string[]).includes(field)) {
        const k = field as keyof FieldErrors;
        if (!fields[k]) fields[k] = field === "thumbnail" ? translateThumbnailError(message) : message;
      } else others.push(message);
    }
    const hasFields = Object.keys(fields).length > 0;
    return { ...empty, fields, banner: others.length > 0 ? others.join(" ") : hasFields ? null : err.message || UNKNOWN_ERROR_MESSAGE };
  }
  return { ...empty, banner: err.message || UNKNOWN_ERROR_MESSAGE };
}

/** Rule ảnh nào chưa có thông điệp tiếng Việt riêng ở server (xem QA T08 BUG-1) thì dùng câu chung. */
function translateThumbnailError(message: string): string {
  return /[a-z]{3,} (must|field)|The /.test(message) ? THUMBNAIL_FORMAT_ERROR : message;
}

/** Thông điệp chung cho lỗi thao tác (xuất bản/ngừng bán/xoá/thứ tự/tải dữ liệu). */
export function courseActionError(err: unknown): string {
  if (err instanceof NetworkError) return err.message;
  if (err instanceof ApiError) {
    if (err.status === 403) return FORBIDDEN_MESSAGE;
    if (err.status === 404) return COURSE_GONE_MESSAGE;
    if (err.code === "COURSE_NOT_PUBLISHABLE") return NOT_PUBLISHABLE_MESSAGE;
    if (err.code === "COURSE_HAS_ENROLLMENTS") return "Không thể xoá vì đã có học sinh đăng ký. Hãy chuyển sang Ngừng bán.";
    if (err.code === "ALREADY_PROCESSED" || err.code === "INVALID_COURSE_STATE") return "Trạng thái khóa học đã thay đổi. Danh sách đã được tải lại.";
    return err.message || UNKNOWN_ERROR_MESSAGE;
  }
  return UNKNOWN_ERROR_MESSAGE;
}

const hasCode = (err: unknown, status: number, code: string) => err instanceof ApiError && err.status === status && err.code === code;
export const isNotPublishable = (err: unknown) => hasCode(err, 422, "COURSE_NOT_PUBLISHABLE");
export const isHasEnrollments = (err: unknown) => hasCode(err, 409, "COURSE_HAS_ENROLLMENTS");
export const isStaleState = (err: unknown) =>
  err instanceof ApiError && err.status === 409 && (err.code === "ALREADY_PROCESSED" || err.code === "INVALID_COURSE_STATE");
export const isNotFound = (err: unknown) => err instanceof ApiError && err.status === 404;
export const isForbidden = (err: unknown) => err instanceof ApiError && err.status === 403;

/** 422 của PUT .../teachers: lỗi giáo viên mới bị khoá nằm ở `errors.teacher_ids` (không phải `teacher_ids.N`). */
export function teacherAssignError(err: unknown): string {
  if (err instanceof ApiError && err.status === 422 && err.errors) {
    const hit = Object.entries(errorMessages(err)).find(([k]) => normalizeErrorKey(k) === "teacher_ids");
    if (hit?.[1][0]) return hit[1][0];
  }
  return courseActionError(err);
}
