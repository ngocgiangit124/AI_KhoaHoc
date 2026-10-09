import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { retryAfterText, UNKNOWN_ERROR_MESSAGE } from "@/lib/auth/errors";
import { formatVnDateTime } from "@/lib/privacy/format";
import { checkoutPreviewSchema, pendingOrderConflictSchema, type CheckoutPreview, type PendingOrderConflict } from "./schemas";

/**
 * Chi tiết lỗi nghiệp vụ nằm trong `errors.*` (api-contract §1.7), KHÔNG phải `context.*`. Ở các lỗi đơn hàng giá trị có thể là
 * chuỗi, số hoặc object (`errors.preview`) nên đọc qua `unknown` (kiểu `ApiError.errors` khai báo `string[]` là chưa đủ).
 */
export function errorField(err: ApiError, key: string): unknown {
  return (err.errors as Record<string, unknown> | undefined)?.[key];
}

function errorString(err: ApiError, key: string): string | undefined {
  const raw = errorField(err, key);
  if (typeof raw === "string" && raw !== "") return raw;
  if (Array.isArray(raw) && typeof raw[0] === "string") return raw[0];
  return undefined;
}

function fallbackMessage(err: unknown): string {
  if (err instanceof NetworkError) return err.message;
  if (err instanceof ApiError) return err.message || UNKNOWN_ERROR_MESSAGE;
  return UNKNOWN_ERROR_MESSAGE;
}

/* ---------- Tải dữ liệu (GET) ---------- */

export type OrderLoadFailure = "session" | "not_verified" | "forbidden" | "not_found" | "throttled" | "error";

export function classifyOrderLoadError(err: unknown): OrderLoadFailure {
  if (err instanceof ApiError) {
    if (err.status === 401) return "session";
    if (err.status === 403) return err.code === "ACCOUNT_NOT_VERIFIED" ? "not_verified" : "forbidden";
    if (err.status === 404) return "not_found";
    if (err.status === 429) return "throttled";
  }
  return "error";
}

/** Mã đơn trong đường dẫn: chữ + số, 6–32 ký tự (mã thật `VV261008K7M2QX`). Sai định dạng -> 404 ngay, không gọi API. */
export function parseOrderCode(raw: string): string | null {
  return /^[A-Za-z0-9]{6,32}$/.test(raw) ? raw.toUpperCase() : null;
}

/* ---------- Giỏ hàng ---------- */

export type CartActionFailure =
  | { kind: "throttled"; message: string }
  | { kind: "banner"; message: string };

/** `PUT /cart/coupon`: lỗi hiện ngay dưới ô nhập. 429 `TOO_MANY_ATTEMPTS` kèm thời gian chờ. */
export function classifyCouponError(err: unknown): string {
  if (err instanceof ApiError) {
    if (err.status === 429) return `Bạn thao tác quá nhanh. ${retryAfterText(err.retryAfterSeconds)}`;
    if (err.status === 422) {
      // Thông điệp tiếng Việt do server quyết định (`COUPON_EXPIRED` có message riêng cho "hết hạn" và "hết lượt").
      const fieldMsg = errorString(err, "code");
      if (fieldMsg) return fieldMsg;
      return err.message;
    }
  }
  return fallbackMessage(err);
}

export function classifyCartError(err: unknown): CartActionFailure {
  if (err instanceof ApiError && err.status === 429) return { kind: "throttled", message: `Bạn thao tác quá nhanh. ${retryAfterText(err.retryAfterSeconds)}` };
  return { kind: "banner", message: fallbackMessage(err) };
}

export type AddToCartOutcome =
  | { type: "in_cart" }
  | { type: "owned" }
  | { type: "message"; message: string };

/** `POST /cart/items` ở trang chi tiết khóa: 409 `ALREADY_IN_CART` coi như thành công; `ALREADY_OWNED` -> tải lại trạng thái. */
export function mapAddToCartError(err: unknown): AddToCartOutcome {
  if (err instanceof ApiError) {
    if (err.status === 409 && err.code === "ALREADY_IN_CART") return { type: "in_cart" };
    if (err.status === 409 && err.code === "ALREADY_OWNED") return { type: "owned" };
    if (err.status === 422) return { type: "message", message: errorString(err, "course_id") ?? "Khóa học này không thể thêm vào giỏ." };
    if (err.status === 429) return { type: "message", message: `Bạn thao tác quá nhanh. ${retryAfterText(err.retryAfterSeconds)}` };
  }
  return { type: "message", message: err instanceof NetworkError ? err.message : "Không thêm được vào giỏ hàng, vui lòng thử lại." };
}

/* ---------- Gửi đơn (POST /checkout) ---------- */

export type CheckoutFailure =
  /** 409: đang có đơn thủ công chờ duyệt, nội dung khác -> hộp "Đặt đơn mới / Giữ đơn cũ". */
  | { kind: "pending_exists"; conflict: PendingOrderConflict }
  /** 409 `CHECKOUT_CHANGED`: giá/mã/khóa đổi; `preview` mới (null nếu server không kèm hoặc sai shape -> tải lại). */
  | { kind: "changed"; preview: CheckoutPreview | null; reasons: string[] }
  /** 429 `MANUAL_ORDER_LIMIT`: hết hạn mức đơn trong ngày. */
  | { kind: "limit"; message: string; resetsAt: string | null }
  | { kind: "throttled"; message: string }
  /** 503 `PAYMENT_DISABLED`: tắt chủ động, KHÔNG tự thử lại. */
  | { kind: "disabled"; message: string }
  | { kind: "cart_empty" }
  | { kind: "not_verified" }
  | { kind: "field"; field: "customer_note" | "payment_method"; message: string }
  | { kind: "banner"; message: string };

export function classifyCheckoutError(err: unknown): CheckoutFailure {
  if (err instanceof ApiError) {
    if (err.status === 409 && err.code === "PENDING_ORDER_EXISTS") {
      const parsed = pendingOrderConflictSchema.safeParse(err.errors);
      if (parsed.success) return { kind: "pending_exists", conflict: parsed.data };
      return { kind: "banner", message: err.message };
    }
    if (err.status === 409 && err.code === "CHECKOUT_CHANGED") {
      const parsed = checkoutPreviewSchema.safeParse(errorField(err, "preview"));
      const raw = errorField(err, "reasons");
      const reasons = Array.isArray(raw) ? raw.filter((r): r is string => typeof r === "string") : [];
      return { kind: "changed", preview: parsed.success ? parsed.data : null, reasons };
    }
    if (err.status === 429 && err.code === "MANUAL_ORDER_LIMIT") {
      return { kind: "limit", message: err.message, resetsAt: errorString(err, "resets_at") ?? null };
    }
    if (err.status === 429) return { kind: "throttled", message: `Bạn thao tác quá nhanh. ${retryAfterText(err.retryAfterSeconds)}` };
    if (err.status === 503 && err.code === "PAYMENT_DISABLED") return { kind: "disabled", message: err.message };
    if (err.status === 422 && err.code === "CART_EMPTY") return { kind: "cart_empty" };
    if (err.status === 403 && err.code === "ACCOUNT_NOT_VERIFIED") return { kind: "not_verified" };
    if (err.status === 422) {
      for (const field of ["customer_note", "payment_method"] as const) {
        const message = errorString(err, field);
        if (message) return { kind: "field", field, message };
      }
    }
  }
  return { kind: "banner", message: fallbackMessage(err) };
}

/** "Từ 00:00, 10/10/2026 bạn có thể đặt lại." (giờ VN) hoặc rỗng khi server không kèm `resets_at`. */
export function limitResetText(resetsAt: string | null): string {
  const t = formatVnDateTime(resetsAt);
  return t ? `Từ ${t} bạn có thể đặt lại.` : "";
}

/* ---------- Huỷ đơn ---------- */

export type CancelFailure =
  /** 409: đơn vừa đổi trạng thái (được duyệt/huỷ/hết hạn) -> tải lại đơn, không thử lại. */
  | { kind: "conflict"; message: string }
  | { kind: "not_found" }
  | { kind: "throttled"; message: string }
  | { kind: "banner"; message: string };

export function classifyCancelError(err: unknown): CancelFailure {
  if (err instanceof ApiError) {
    if (err.status === 409) return { kind: "conflict", message: err.message };
    if (err.status === 404) return { kind: "not_found" };
    if (err.status === 429) return { kind: "throttled", message: `Bạn thao tác quá nhanh. ${retryAfterText(err.retryAfterSeconds)}` };
  }
  return { kind: "banner", message: fallbackMessage(err) };
}

const CHANGE_REASONS: Record<string, string> = {
  PRICE_CHANGED: "Giá một số khóa đã thay đổi.",
  COUPON_REMOVED: "Mã giảm giá không còn áp dụng được nên đã được gỡ.",
  COUPON_EXHAUSTED: "Mã giảm giá đã hết lượt nên đã được gỡ.",
  ITEMS_CHANGED: "Một số khóa trong giỏ không còn khả dụng.",
};

/** Câu mô tả vì sao giỏ đổi (mã lạ bị bỏ). Rỗng -> câu chung. */
export function changeReasonText(reasons: string[]): string {
  const lines = reasons.map((r) => CHANGE_REASONS[r]).filter((t): t is string => Boolean(t));
  return lines.length > 0 ? lines.join(" ") : "Giá hoặc nội dung giỏ hàng đã thay đổi.";
}
