import type { ApiErrorBody } from "./types";

/** Lỗi nghiệp vụ/HTTP từ API (4xx/5xx với body JSON đúng envelope api-contract §1.7). */
export class ApiError extends Error {
  readonly status: number;
  readonly code: string | undefined;
  readonly errors: Record<string, string[]> | undefined;
  readonly requestId: string | undefined;
  /** Giây chờ theo header `Retry-After` (429), nếu server gửi dạng số giây. */
  readonly retryAfterSeconds: number | undefined;

  constructor(status: number, body: ApiErrorBody, retryAfterSeconds?: number) {
    super(body.message || "Đã có lỗi xảy ra, vui lòng thử lại sau.");
    this.name = "ApiError";
    this.status = status;
    this.code = body.code;
    this.errors = body.errors;
    this.requestId = body.request_id;
    this.retryAfterSeconds = retryAfterSeconds;
  }
}

/**
 * Lỗi mạng (mất kết nối, CORS bị chặn, DNS lỗi...) — KHÔNG BAO GIỜ được coi là mất phiên
 * (yêu cầu tasks.md FE0). Phân biệt rõ với ApiError để `ForcedLogoutOverlay` không bật sai.
 */
export class NetworkError extends Error {
  constructor(cause: unknown) {
    super("Không thể kết nối tới máy chủ. Vui lòng kiểm tra kết nối mạng và thử lại.");
    this.name = "NetworkError";
    this.cause = cause;
  }
}

export const FORCED_LOGOUT_EVENT = "forced-logout";
export const LOGIN_REQUIRED_EVENT = "login-required";

/** HS bị đăng xuất vì đăng nhập thiết bị khác (ADR-003) — chặn toàn màn hình, không tự chuyển trang. */
const FORCED_LOGOUT_CODES = new Set(["SESSION_REPLACED"]);

/** Phiên hết hiệu lực theo cách "êm" hơn — chuyển hướng về trang đăng nhập. */
const LOGIN_REQUIRED_CODES = new Set([
  "SESSION_EXPIRED",
  "SESSION_REVOKED",
  "UNAUTHENTICATED",
  "STAFF_IDLE_TIMEOUT",
]);

export type AuthEventCode =
  | "SESSION_REPLACED"
  | "SESSION_EXPIRED"
  | "SESSION_REVOKED"
  | "UNAUTHENTICATED"
  | "STAFF_IDLE_TIMEOUT";

export interface AuthEventDetail {
  code: AuthEventCode;
}

/**
 * Phát sự kiện DOM toàn cục theo mã lỗi (chỉ chạy được ở trình duyệt — `window` không tồn tại
 * khi authFetch được gọi ở server). `ForcedLogoutOverlay` (packages/ui) lắng nghe sự kiện này.
 */
export function dispatchAuthEventIfNeeded(code: string | undefined): void {
  if (!code || typeof window === "undefined") return;

  if (FORCED_LOGOUT_CODES.has(code)) {
    window.dispatchEvent(
      new CustomEvent<AuthEventDetail>(FORCED_LOGOUT_EVENT, {
        detail: { code: code as AuthEventCode },
      }),
    );
    return;
  }

  if (LOGIN_REQUIRED_CODES.has(code)) {
    window.dispatchEvent(
      new CustomEvent<AuthEventDetail>(LOGIN_REQUIRED_EVENT, {
        detail: { code: code as AuthEventCode },
      }),
    );
  }
}
