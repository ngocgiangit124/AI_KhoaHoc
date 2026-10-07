import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { UNKNOWN_ERROR_MESSAGE } from "@/lib/auth/errors";

export const FORBIDDEN_MESSAGE = "Bạn không có quyền sửa nội dung khóa học này.";
export const GONE_MESSAGE = "Mục này không còn tồn tại. Danh sách đã được tải lại.";
export const MISMATCH_MESSAGE = "Nội dung khóa học vừa được thay đổi ở nơi khác. Đã tải lại thứ tự mới, hãy thử lại.";

export const isMismatch = (err: unknown) => err instanceof ApiError && err.status === 422 && err.code === "CURRICULUM_MISMATCH";
export const isGone = (err: unknown) => err instanceof ApiError && err.status === 404;
export const isForbiddenError = (err: unknown) => err instanceof ApiError && err.status === 403;

/** Thông điệp cho mọi thao tác chương/bài/video (banner hoặc toast). */
export function curriculumError(err: unknown): string {
  if (err instanceof NetworkError) return err.message;
  if (!(err instanceof ApiError)) return UNKNOWN_ERROR_MESSAGE;
  switch (err.code) {
    case "CURRICULUM_MISMATCH":
      return MISMATCH_MESSAGE;
    case "CHAPTER_HAS_PROGRESS":
      return "Không thể xoá chương vì đã có học sinh học bài trong chương này.";
    case "LESSON_HAS_PROGRESS":
      return "Không thể xoá bài vì đã có học sinh học bài này.";
    case "COURSE_LAST_LESSON":
      return "Khóa học đang xuất bản cần ít nhất 1 bài. Hãy ngừng bán khóa học trước khi xoá bài cuối cùng.";
    case "VIDEO_TOO_LARGE":
      return "Tệp quá lớn. Video tối đa 1 GB.";
    case "VIDEO_QUOTA_EXCEEDED":
      return "Đã hết hạn mức tải video trong ngày. Hãy thử lại vào ngày mai hoặc liên hệ quản trị viên.";
    case "VIDEO_PROVIDER_UNAVAILABLE":
      return "Dịch vụ video tạm thời không dùng được. Vui lòng thử lại sau ít phút.";
    case "VIDEO_INVALID":
      return err.message || "Tệp tải lên không phải video hợp lệ.";
  }
  if (err.status === 403) return FORBIDDEN_MESSAGE;
  if (err.status === 404) return GONE_MESSAGE;
  if (err.status === 429) return `Bạn thao tác quá nhanh. Hãy thử lại sau ${err.retryAfterSeconds ?? 60} giây.`;
  return err.message || UNKNOWN_ERROR_MESSAGE;
}

/** Lỗi 422 theo field của form bài (`title`, `external_url`, `video_source`...). Trả rỗng nếu không phải 422. */
export function lessonFieldErrors(err: unknown): Record<string, string> {
  const out: Record<string, string> = {};
  if (err instanceof ApiError && err.status === 422 && err.errors) {
    for (const [key, msgs] of Object.entries(err.errors)) if (msgs[0]) out[key.replace(/\.\d+$/, "")] = msgs[0];
  }
  return out;
}
