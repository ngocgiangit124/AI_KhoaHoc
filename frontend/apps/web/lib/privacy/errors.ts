import { ApiError, NetworkError, errorString } from "@vitaminvui/api-client";
import { classifyOtpSendError, retryAfterText, UNKNOWN_ERROR_MESSAGE } from "@/lib/auth/errors";
import { formatVnDateTime } from "./format";

function fallbackMessage(err: unknown): string {
  if (err instanceof NetworkError) return err.message;
  if (err instanceof ApiError) return err.message || UNKNOWN_ERROR_MESSAGE;
  return UNKNOWN_ERROR_MESSAGE;
}

/** Lỗi tải dữ liệu (GET): thông điệp ngắn cho khối + nút "Thử lại". */
export function loadErrorMessage(err: unknown): string {
  if (err instanceof ApiError && err.status === 429) return `Bạn thao tác quá nhanh. ${retryAfterText(err.retryAfterSeconds)}`;
  if (err instanceof ApiError && err.status === 403) return "Tài khoản này không dùng được mục này.";
  return err instanceof NetworkError ? err.message : "Không tải được dữ liệu. Vui lòng thử lại.";
}

export type ParentContactFailure =
  | { kind: "fields"; errors: Partial<Record<"parent_email" | "parent_phone" | "current_password", string>> }
  | { kind: "throttled"; message: string }
  | { kind: "banner"; message: string };

/** `PUT /me/parent-contact`: 422 theo field; 429 chung hạn mức với đổi liên hệ/mật khẩu. */
export function classifyParentContactError(err: unknown): ParentContactFailure {
  if (err instanceof ApiError) {
    if (err.status === 429) return { kind: "throttled", message: `Bạn đã thử quá nhiều lần. ${retryAfterText(err.retryAfterSeconds)}` };
    if (err.status === 422 && err.errors) {
      const errors: Partial<Record<"parent_email" | "parent_phone" | "current_password", string>> = {};
      for (const f of ["parent_email", "parent_phone", "current_password"] as const) {
        const m = errorString(err, f);
        if (m) errors[f] = m;
      }
      if (Object.keys(errors).length > 0) return { kind: "fields", errors };
    }
  }
  return { kind: "banner", message: fallbackMessage(err) };
}

export type ExportFailure =
  /** 429 `DATA_EXPORT_LIMIT`: hết lượt trong ngày; `resetsAt` = `errors.resets_at` (có thể thiếu). */
  | { kind: "limit"; resetsAt: string | null }
  | { kind: "password"; message: string }
  | { kind: "throttled"; message: string }
  | { kind: "banner"; message: string; requestId?: string };

export function classifyExportError(err: unknown): ExportFailure {
  if (err instanceof ApiError) {
    if (err.status === 429) {
      if (err.code === "DATA_EXPORT_LIMIT") return { kind: "limit", resetsAt: errorString(err, "resets_at") ?? null };
      return { kind: "throttled", message: `Bạn đã thử quá nhiều lần. ${retryAfterText(err.retryAfterSeconds)}` };
    }
    if (err.status === 422) {
      const m = errorString(err, "current_password");
      if (m) return { kind: "password", message: m };
    }
    return { kind: "banner", message: err.message || UNKNOWN_ERROR_MESSAGE, requestId: err.requestId };
  }
  return { kind: "banner", message: fallbackMessage(err) };
}

export const EXPORT_LIMIT_TEXT = (limit: number, resetsAt: string | null): string => {
  const when = formatVnDateTime(resetsAt);
  return `Bạn đã tải ${limit} lần hôm nay. Thử lại sau ${when || "00:00 ngày mai"}.`;
};

export type DeleteSendFailure =
  | { kind: "not-verified"; message: string }
  | { kind: "pending-payment"; message: string; orderCode?: string }
  | { kind: "wait"; seconds: number; message: string }
  | { kind: "locked"; message: string }
  | { kind: "delivery"; message: string }
  | { kind: "other"; message: string };

/** US-022: đơn thủ công đang chờ -> `errors.pending_order_code` (liên kết tới đơn để tự huỷ trong "Đơn của tôi"). */
export function pendingOrderCode(err: ApiError): string | undefined {
  const code = errorString(err, "pending_order_code");
  return code && /^[A-Za-z0-9]{6,32}$/.test(code) ? code : undefined;
}

export function pendingPaymentMessage(err: ApiError): string {
  // Có đơn thủ công chờ duyệt: dùng thông điệp server ("Hãy huỷ đơn trong Đơn của tôi nếu không còn muốn mua, rồi thử lại").
  if (pendingOrderCode(err) && err.message) return err.message;
  const when = formatVnDateTime(errorString(err, "retry_after_at"));
  return when ? `Bạn đang có đơn chờ thanh toán. Hãy thử lại sau ${when}.` : "Bạn đang có đơn chờ thanh toán. Hãy hoàn tất hoặc đợi đơn hết hạn rồi thử lại.";
}

/** `POST /me/account/delete/otp`: 403 chưa xác thực email, 409 đơn đang chờ thanh toán, còn lại như gửi OTP thường. */
export function classifyDeleteSendError(err: unknown): DeleteSendFailure {
  if (err instanceof ApiError) {
    if (err.code === "ACCOUNT_NOT_VERIFIED") return { kind: "not-verified", message: err.message || "Bạn cần xác thực email trước khi xoá tài khoản." };
    if (err.code === "ACCOUNT_HAS_PENDING_PAYMENT") return { kind: "pending-payment", message: pendingPaymentMessage(err), orderCode: pendingOrderCode(err) };
  }
  return classifyOtpSendError(err);
}

export type ConsentAcceptFailure =
  | { kind: "version-changed"; currentVersion: string | null }
  | { kind: "fields"; message: string }
  | { kind: "throttled"; message: string }
  | { kind: "other"; message: string };

export function classifyAcceptError(err: unknown): ConsentAcceptFailure {
  if (err instanceof ApiError) {
    if (err.code === "CONSENT_VERSION_CHANGED") return { kind: "version-changed", currentVersion: errorString(err, "current_version") ?? null };
    if (err.status === 429) return { kind: "throttled", message: `Bạn thao tác quá nhanh. ${retryAfterText(err.retryAfterSeconds)}` };
    if (err.status === 422) return { kind: "fields", message: errorString(err, "accept_terms") ?? errorString(err, "accept_privacy") ?? err.message };
  }
  return { kind: "other", message: fallbackMessage(err) };
}

export type UnsubscribeFailure = { kind: "invalid-link" } | { kind: "retry"; message: string };

/** 422 (thiếu/sai dạng token) -> liên kết không hợp lệ; 429/mạng/5xx -> thử lại được. */
export function classifyUnsubscribeError(err: unknown): UnsubscribeFailure {
  if (err instanceof ApiError && err.status === 422) return { kind: "invalid-link" };
  if (err instanceof ApiError && err.status === 429) return { kind: "retry", message: `Bạn thao tác quá nhiều lần. ${retryAfterText(err.retryAfterSeconds)}` };
  return { kind: "retry", message: err instanceof NetworkError ? err.message : "Chưa gửi được yêu cầu. Vui lòng thử lại." };
}
