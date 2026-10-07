import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { isValidCourseSlug } from "@/lib/catalog/query";

/** Phân loại lỗi `GET /learn/lessons/{id}/playback` để UI chọn đúng thông điệp (api-contract §2.4). */
export type PlaybackErrorKind =
  | "not_owned" // 403 COURSE_NOT_OWNED (kể cả bị thu hồi giữa chừng)
  | "not_found" // 404 NOT_FOUND
  | "no_video" // 404 VIDEO_NOT_AVAILABLE
  | "processing" // 409 VIDEO_NOT_READY
  | "throttled" // 429
  | "unavailable" // 503 VIDEO_PROVIDER_UNAVAILABLE, 5xx
  | "session" // 401: SessionEndedGate lo hộp thoại
  | "network"
  | "unknown";

export function classifyPlaybackError(err: unknown): PlaybackErrorKind {
  if (err instanceof NetworkError) return "network";
  if (err instanceof ApiError) {
    if (err.status === 401) return "session";
    if (err.status === 403) return "not_owned";
    if (err.status === 404) return err.code === "VIDEO_NOT_AVAILABLE" ? "no_video" : "not_found";
    if (err.status === 409 && err.code === "VIDEO_NOT_READY") return "processing";
    if (err.status === 429) return "throttled";
    if (err.status >= 500) return "unavailable";
  }
  return "unknown";
}

/** Lỗi KHÔNG nên thử lại tự động (thử lại cũng vô ích hoặc có hại). */
export function isTerminalPlaybackError(kind: PlaybackErrorKind): boolean {
  return kind === "not_owned" || kind === "not_found" || kind === "no_video" || kind === "processing" || kind === "session";
}

export const PLAYBACK_MESSAGES: Record<PlaybackErrorKind, string> = {
  not_owned: "Bạn chưa sở hữu khóa học này hoặc quyền truy cập đã bị thu hồi.",
  not_found: "Không tìm thấy bài học này.",
  no_video: "Bài học này chưa có video.",
  processing: "Video bài này đang được xử lý.",
  throttled: "Bạn thao tác hơi nhanh. Đợi một chút rồi thử lại.",
  unavailable: "Không tải được video, vui lòng thử lại.",
  session: "Phiên đăng nhập đã kết thúc.",
  network: "Không tải được video, vui lòng thử lại.",
  unknown: "Không tải được video, vui lòng thử lại.",
};

/**
 * 403 `COURSE_NOT_OWNED` từ `/learn/*` có `errors.course {id, slug, title}` khi khóa đang published (api-contract §1.7).
 * Body do server trả nhưng vẫn kiểm slug theo định dạng catalog trước khi dùng làm đường dẫn chuyển hướng.
 */
export function courseRefFromError(err: unknown): { slug: string; title: string } | null {
  if (!(err instanceof ApiError) || err.status !== 403) return null;
  const course = (err.errors as Record<string, unknown> | undefined)?.course;
  if (typeof course !== "object" || course === null) return null;
  const { slug, title } = course as { slug?: unknown; title?: unknown };
  if (typeof slug !== "string" || !isValidCourseSlug(slug)) return null;
  return { slug, title: typeof title === "string" ? title : "" };
}
