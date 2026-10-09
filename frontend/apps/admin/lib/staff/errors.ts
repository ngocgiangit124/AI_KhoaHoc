import { ApiError, NetworkError, errorMessages } from "@vitaminvui/api-client";
import { UNKNOWN_ERROR_MESSAGE } from "@/lib/auth/errors";

export const FORBIDDEN_MESSAGE = "Bạn không có quyền thực hiện thao tác này.";
export const STAFF_GONE_MESSAGE = "Tài khoản không còn tồn tại hoặc không phải tài khoản staff.";

export const CREATE_FIELDS = ["name", "email", "role"] as const;
export type CreateField = (typeof CREATE_FIELDS)[number];
export type CreateErrors = Partial<Record<CreateField, string>>;

export function retryText(err: ApiError): string {
  return err.retryAfterSeconds ? ` Vui lòng thử lại sau ${err.retryAfterSeconds} giây.` : " Vui lòng thử lại sau ít phút.";
}

export interface CreateFailure {
  fields: CreateErrors;
  banner: string | null;
}

/** Lỗi tạo staff: 422 → dưới đúng ô (+ hộp tóm tắt); 403/429/mạng/khác → banner. */
export function classifyCreateError(err: unknown): CreateFailure {
  if (err instanceof NetworkError) return { fields: {}, banner: err.message };
  if (!(err instanceof ApiError)) return { fields: {}, banner: UNKNOWN_ERROR_MESSAGE };
  if (err.status === 403) return { fields: {}, banner: FORBIDDEN_MESSAGE };
  if (err.status === 429) return { fields: {}, banner: `Bạn thao tác quá nhanh.${retryText(err)}` };
  if (err.status === 422 && err.errors) {
    const fields: CreateErrors = {};
    const others: string[] = [];
    for (const [key, msgs] of Object.entries(errorMessages(err))) {
      const message = msgs[0];
      if (!message) continue;
      if ((CREATE_FIELDS as readonly string[]).includes(key)) {
        if (!fields[key as CreateField]) fields[key as CreateField] = message;
      } else others.push(message);
    }
    const hasFields = Object.keys(fields).length > 0;
    return { fields, banner: others.length > 0 ? others.join(" ") : hasFields ? null : err.message || UNKNOWN_ERROR_MESSAGE };
  }
  return { fields: {}, banner: err.message || UNKNOWN_ERROR_MESSAGE };
}

/** Thông điệp cho tải danh sách / khoá / mở khoá / đặt lại mật khẩu / đổi vai trò. 409 và 422 dùng nguyên văn lời server (đã là tiếng Việt). */
export function staffActionError(err: unknown): string {
  if (err instanceof NetworkError) return err.message;
  if (err instanceof ApiError) {
    if (err.status === 403) return err.code === "ACCOUNT_LOCKED" ? err.message || FORBIDDEN_MESSAGE : FORBIDDEN_MESSAGE;
    if (err.status === 404) return STAFF_GONE_MESSAGE;
    if (err.status === 429) return `Bạn thao tác quá nhanh.${retryText(err)}`;
    return err.message || UNKNOWN_ERROR_MESSAGE;
  }
  return UNKNOWN_ERROR_MESSAGE;
}

const is = (err: unknown, status: number) => err instanceof ApiError && err.status === status;
export const isNotFound = (err: unknown) => is(err, 404);
export const isForbidden = (err: unknown) => is(err, 403);
/** 404 / 409 ALREADY_PROCESSED: dữ liệu trên màn đã cũ → nên tải lại danh sách. */
export const isStale = (err: unknown) => is(err, 404) || (err instanceof ApiError && err.status === 409 && err.code === "ALREADY_PROCESSED");
