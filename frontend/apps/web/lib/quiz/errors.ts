import { ApiError, NetworkError } from "@vitaminvui/api-client";

/** Lỗi khi bắt đầu/mở lượt làm bài → màn thông báo tương ứng. */
export type QuizLoadFailure = "not_owned" | "not_found" | "not_ready" | "throttled" | "session" | "error";

export function classifyQuizLoadError(err: unknown): QuizLoadFailure {
  if (err instanceof ApiError) {
    if (err.status === 401) return "session";
    if (err.status === 403) return "not_owned";
    if (err.status === 404) return "not_found";
    if (err.status === 422 && err.code === "QUIZ_NOT_READY") return "not_ready";
    if (err.status === 429) return "throttled";
  }
  return "error";
}

/**
 * Kết cục của một lần PUT đáp án thất bại:
 * - `closed`: lượt đã nộp/hết hạn (409) → chuyển sang xem kết quả;
 * - `revoked`: mất quyền (403) hoặc lượt không còn (404) → dừng, báo;
 * - `session`: 401 → hộp thoại mất phiên lo, giữ đáp án trong bộ nhớ, ngừng thử lại;
 * - `reject`: 422 (câu/đáp án không hợp lệ) → bỏ đáp án này, báo lỗi ở câu;
 * - `retry`: mạng/429/5xx → giữ đáp án và gửi lại sau.
 */
export type SaveFailure = "closed" | "revoked" | "session" | "reject" | "retry";

export function classifySaveError(err: unknown): SaveFailure {
  if (err instanceof ApiError) {
    if (err.status === 401) return "session";
    if (err.status === 409) return "closed";
    if (err.status === 403 || err.status === 404) return "revoked";
    if (err.status === 422) return "reject";
    return "retry";
  }
  if (err instanceof NetworkError) return "retry";
  return "retry";
}
