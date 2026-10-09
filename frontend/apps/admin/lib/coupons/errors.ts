import { ApiError, NetworkError, errorField, errorMessages } from "@vitaminvui/api-client";
import { UNKNOWN_ERROR_MESSAGE } from "@/lib/auth/errors";
import { FIELD_ORDER, type FieldErrors, type FieldKey } from "./form";

export const FORBIDDEN_MESSAGE = "Bạn không có quyền thực hiện thao tác này.";
export const COUPON_GONE_MESSAGE = "Mã giảm giá không còn tồn tại.";
export const IN_USE_MESSAGE = "Không thể xoá vì mã đã được sử dụng. Hãy tắt mã thay thế.";
const LOCKED_FIELD_LABELS: Record<string, string> = { code: "mã", discount_type: "loại giảm", discount_value: "giá trị giảm" };

export interface CouponFormFailure {
  fields: FieldErrors;
  banner: string | null;
  /** Mã đã bị xoá từ nơi khác (404). */
  gone: boolean;
  /** Dữ liệu trên màn hình đã cũ (COUPON_LOCKED): nên tải lại bản mới nhất. */
  stale: boolean;
}

/** `course_ids.3` → `course_ids`. */
export const normalizeErrorKey = (key: string) => key.replace(/\.\d+$/, "").replace(/\.\*$/, "");

const isKnown = (k: string): k is FieldKey => (FIELD_ORDER as readonly string[]).includes(k);

export function retryText(err: ApiError): string {
  return err.retryAfterSeconds ? ` Vui lòng thử lại sau ${err.retryAfterSeconds} giây.` : " Vui lòng thử lại sau ít phút.";
}

/** Lỗi lưu form: 422 → dưới đúng field; COUPON_LOCKED/403/404/429/mạng → banner. */
export function classifyCouponFormError(err: unknown): CouponFormFailure {
  const empty: CouponFormFailure = { fields: {}, banner: null, gone: false, stale: false };
  if (err instanceof NetworkError) return { ...empty, banner: err.message };
  if (!(err instanceof ApiError)) return { ...empty, banner: UNKNOWN_ERROR_MESSAGE };
  if (err.status === 403) return { ...empty, banner: FORBIDDEN_MESSAGE };
  if (err.status === 404) return { ...empty, banner: `${COUPON_GONE_MESSAGE} Vui lòng quay lại danh sách.`, gone: true };
  if (err.status === 429) return { ...empty, banner: `Bạn thao tác quá nhanh.${retryText(err)}` };
  if (err.status === 422 && err.code === "COUPON_LOCKED") {
    const rawFields = errorField(err, "fields");
    const raw = Array.isArray(rawFields) ? rawFields.filter((f): f is string => typeof f === "string") : [];
    const names = raw.map((f) => LOCKED_FIELD_LABELS[f] ?? f).join(", ");
    return {
      ...empty,
      stale: true,
      banner: `Mã đã được sử dụng nên không đổi được ${names || "mã, loại hoặc giá trị giảm"}. Form đã được nạp lại theo thông tin mới nhất; các thay đổi chưa lưu đã được bỏ, vui lòng nhập lại nếu cần.`,
    };
  }
  if (err.status === 422 && err.errors) {
    const fields: FieldErrors = {};
    const others: string[] = [];
    for (const [key, msgs] of Object.entries(errorMessages(err))) {
      const message = msgs[0];
      if (!message) continue;
      const field = normalizeErrorKey(key);
      if (isKnown(field)) {
        if (!fields[field]) fields[field] = message;
      } else others.push(message);
    }
    const hasFields = Object.keys(fields).length > 0;
    return { ...empty, fields, banner: others.length > 0 ? others.join(" ") : hasFields ? null : err.message || UNKNOWN_ERROR_MESSAGE };
  }
  return { ...empty, banner: err.message || UNKNOWN_ERROR_MESSAGE };
}

/** Thông điệp chung cho tải dữ liệu / bật-tắt / xoá. */
export function couponActionError(err: unknown): string {
  if (err instanceof NetworkError) return err.message;
  if (err instanceof ApiError) {
    if (err.status === 403) return FORBIDDEN_MESSAGE;
    if (err.status === 404) return COUPON_GONE_MESSAGE;
    if (err.status === 429) return `Bạn thao tác quá nhanh.${retryText(err)}`;
    if (err.code === "COUPON_IN_USE") return IN_USE_MESSAGE;
    return err.message || UNKNOWN_ERROR_MESSAGE;
  }
  return UNKNOWN_ERROR_MESSAGE;
}

const is = (err: unknown, status: number, code?: string) => err instanceof ApiError && err.status === status && (code === undefined || err.code === code);
export const isNotFound = (err: unknown) => is(err, 404);
export const isForbidden = (err: unknown) => is(err, 403);
export const isInUse = (err: unknown) => is(err, 409, "COUPON_IN_USE");
