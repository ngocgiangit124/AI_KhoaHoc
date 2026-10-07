import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { UNKNOWN_ERROR_MESSAGE } from "@/lib/auth/errors";

export const FORBIDDEN_MESSAGE = "Bạn không có quyền xử lý yêu cầu này (chỉ giáo viên phụ trách khóa, Quản lý trang và Admin).";

/** Kết quả phân loại lỗi duyệt/từ chối để màn quyết định hiển thị và có tải lại danh sách hay không. */
export interface DecisionFailure {
  message: string;
  /** Lỗi theo ô lý do (422 `errors.reason`). */
  reasonError: string | null;
  /** Yêu cầu không còn ở trạng thái chờ / không còn quyền / không tồn tại: nên tải lại danh sách. */
  stale: boolean;
}

export function classifyDecisionError(err: unknown): DecisionFailure {
  if (err instanceof NetworkError) return { message: err.message, reasonError: null, stale: false };
  if (!(err instanceof ApiError)) return { message: UNKNOWN_ERROR_MESSAGE, reasonError: null, stale: false };
  if (err.status === 409 && err.code === "ALREADY_PROCESSED") {
    return { message: "Yêu cầu này đã được người khác xử lý. Danh sách đã được tải lại.", reasonError: null, stale: true };
  }
  if (err.status === 409 && err.code === "COURSE_UNAVAILABLE") {
    return { message: "Khóa học đã bị xoá nên không duyệt được (vẫn có thể từ chối).", reasonError: null, stale: false };
  }
  if (err.status === 422 && err.code === "COURSE_NOT_FREE") {
    return { message: "Khóa học đã chuyển sang có phí nên không duyệt được (vẫn có thể từ chối).", reasonError: null, stale: false };
  }
  if (err.status === 422 && err.errors?.["reason"]?.[0]) {
    const reasonError = err.errors["reason"][0];
    return { message: reasonError, reasonError, stale: false };
  }
  if (err.status === 403) return { message: `${FORBIDDEN_MESSAGE} Danh sách đã được tải lại.`, reasonError: null, stale: true };
  if (err.status === 404) return { message: "Yêu cầu không còn tồn tại. Danh sách đã được tải lại.", reasonError: null, stale: true };
  if (err.status === 429) return { message: "Bạn thao tác quá nhanh. Vui lòng đợi một lát rồi thử lại.", reasonError: null, stale: false };
  return { message: err.message || UNKNOWN_ERROR_MESSAGE, reasonError: null, stale: false };
}

/** Lỗi tải danh sách. */
export function listErrorMessage(err: unknown): string {
  if (err instanceof NetworkError) return err.message;
  if (err instanceof ApiError) {
    if (err.status === 403) return FORBIDDEN_MESSAGE;
    if (err.status === 422) return "Bộ lọc không hợp lệ. Hãy xoá bộ lọc và thử lại.";
    return err.message || UNKNOWN_ERROR_MESSAGE;
  }
  return UNKNOWN_ERROR_MESSAGE;
}
