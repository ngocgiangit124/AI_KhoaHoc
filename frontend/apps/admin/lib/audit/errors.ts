import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { UNKNOWN_ERROR_MESSAGE } from "@/lib/auth/errors";
import { AuditContractError } from "./api";

export const isForbidden = (err: unknown) => err instanceof ApiError && err.status === 403 && err.code !== "ACCOUNT_LOCKED";

/** Thông điệp cho lỗi tải nhật ký. 422 (lọc sai) dùng lời server (tiếng Việt) gộp theo field. */
export function auditLoadError(err: unknown): string {
  if (err instanceof NetworkError) return err.message;
  if (err instanceof AuditContractError) return err.message;
  if (err instanceof ApiError) {
    if (err.status === 422) {
      const raw: unknown = err.errors;
      const msgs: string[] = [];
      if (raw && typeof raw === "object") {
        for (const v of Object.values(raw as Record<string, unknown>)) {
          if (Array.isArray(v) && typeof v[0] === "string") msgs.push(v[0]);
        }
      }
      return msgs.length > 0 ? msgs.join(" ") : "Bộ lọc không hợp lệ. Hãy kiểm tra lại các ô lọc.";
    }
    if (err.status === 429) {
      const wait = err.retryAfterSeconds ? ` Vui lòng thử lại sau ${err.retryAfterSeconds} giây.` : " Vui lòng thử lại sau ít phút.";
      return `Bạn thao tác quá nhanh.${wait}`;
    }
    return err.message || UNKNOWN_ERROR_MESSAGE;
  }
  return UNKNOWN_ERROR_MESSAGE;
}
