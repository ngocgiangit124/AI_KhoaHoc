import { ApiError, NetworkError } from "@vitaminvui/api-client";
import type { ViewerState } from "./schemas";

/** Trạng thái tải của `viewer-state` (chỉ gọi khi đã có phiên). */
export type ViewerStatus = "idle" | "loading" | "ready" | "error";

export type AuthStatus = "loading" | "guest" | "user" | "error";

export interface CtaInput {
  auth: AuthStatus;
  viewerStatus: ViewerStatus;
  viewer: ViewerState | null;
  isFree: boolean;
  courseId: number;
  enrolling: boolean;
  /** Đang gọi `POST /cart/items`. */
  addingToCart?: boolean;
  /** `paid_checkout_enabled` của `/config/public`. */
  paidCheckoutEnabled: boolean;
}

export type CtaModel =
  | { kind: "skeleton" }
  | { kind: "retry"; label: string }
  /** Khách: bấm -> /dang-nhap?next=... */
  | { kind: "login"; label: string }
  | { kind: "register_free"; label: string; busy: boolean }
  | { kind: "pending"; label: string }
  /** Khóa có phí khi thanh toán tạm khoá: giá + khối "Sắp mở bán", KHÔNG có nút mua (design §12.2). */
  | { kind: "coming_soon" }
  /** Khóa có phí, chưa trong giỏ: "Thêm vào giỏ" (POST /cart/items). */
  | { kind: "add_to_cart"; label: string; busy: boolean }
  /** Khóa đã trong giỏ: liên kết "Xem giỏ hàng". */
  | { kind: "in_cart"; label: string; href: string }
  | { kind: "owned"; label: string; href: string };

export function learnHref(courseId: number, resumeLessonId: number | null): string {
  return resumeLessonId !== null ? `/hoc/${courseId}/bai/${resumeLessonId}` : `/hoc/${courseId}`;
}

/**
 * Hành động chính ở trang chi tiết = `viewer_state` x `paid_checkout_enabled` (design-system-v2 §12.2).
 * `owned` luôn thắng: khóa đã sở hữu vẫn "Tiếp tục học" kể cả khi thanh toán đang khoá.
 */
export function resolveCta(input: CtaInput): CtaModel {
  if (input.auth === "loading") return { kind: "skeleton" };
  if (input.auth === "error") return { kind: "retry", label: "Thử lại" };
  if (input.auth === "guest") {
    if (input.isFree) return { kind: "login", label: "Đăng ký học miễn phí" };
    return input.paidCheckoutEnabled ? { kind: "login", label: "Mua khóa học" } : { kind: "coming_soon" };
  }

  // Đã đăng nhập: chờ viewer-state.
  if (input.viewerStatus === "idle" || input.viewerStatus === "loading") return { kind: "skeleton" };
  if (input.viewerStatus === "error" || !input.viewer) return { kind: "retry", label: "Thử lại" };

  switch (input.viewer.viewer_state) {
    case "owned":
      return { kind: "owned", label: "Tiếp tục học", href: learnHref(input.courseId, input.viewer.resume_lesson_id) };
    case "pending_approval":
      return { kind: "pending", label: "Đang chờ duyệt" };
    case "can_register_free":
      return { kind: "register_free", label: "Đăng ký học miễn phí", busy: input.enrolling };
    case "in_cart":
      return input.paidCheckoutEnabled ? { kind: "in_cart", label: "Xem giỏ hàng", href: "/gio-hang" } : { kind: "coming_soon" };
    case "can_buy":
      return input.paidCheckoutEnabled
        ? { kind: "add_to_cart", label: "Thêm vào giỏ", busy: input.addingToCart ?? false }
        : { kind: "coming_soon" };
  }
}

/** Tập khóa của người dùng lấy 1 lần cho cả trang danh mục (thay cho `viewer-state` từng khóa). */
export interface CardOwnership {
  ownedIds: ReadonlySet<number>;
  pendingIds: ReadonlySet<number>;
  cartIds: ReadonlySet<number>;
}

/**
 * Suy ra `viewer_state` của một khóa CÓ PHÍ từ các tập trên, cùng thứ tự ưu tiên với server
 * (`owned` > `pending_approval` > `in_cart` > `can_buy`; api-contract viewer-state) để thẻ khóa dùng lại `resolveCta`.
 */
export function viewerFromOwnership(courseId: number, own: CardOwnership): ViewerState {
  const viewer_state = own.ownedIds.has(courseId)
    ? "owned"
    : own.pendingIds.has(courseId)
      ? "pending_approval"
      : own.cartIds.has(courseId)
        ? "in_cart"
        : "can_buy";
  return { viewer_state, resume_lesson_id: null };
}

export type FreeEnrollOutcome =
  | { type: "verify_account" }
  | { type: "pending" }
  | { type: "owned" }
  | { type: "refresh"; message: string }
  | { type: "message"; message: string };

/** Map lỗi `POST /courses/{id}/free-enrollments` (api-contract T14 chốt) sang hành động UI. */
export function mapFreeEnrollError(err: unknown): FreeEnrollOutcome {
  if (err instanceof NetworkError) return { type: "message", message: err.message };
  if (err instanceof ApiError) {
    if (err.status === 403 && err.code === "ACCOUNT_NOT_VERIFIED") return { type: "verify_account" };
    // `PARENT_CONSENT_REQUIRED` không còn được phát (ADR-006); nếu gặp mã cũ thì rơi xuống nhánh 403 chung bên dưới.
    if (err.status === 409 && err.code === "ENROLLMENT_PENDING") return { type: "pending" };
    if (err.status === 409 && err.code === "ALREADY_OWNED") return { type: "owned" };
    if (err.status === 422 && err.code === "COURSE_NOT_FREE") {
      return { type: "refresh", message: "Khóa học này không còn miễn phí. Vui lòng tải lại trang." };
    }
    if (err.status === 404) return { type: "refresh", message: "Khóa học không còn khả dụng." };
    if (err.status === 429) return { type: "message", message: "Bạn thao tác quá nhanh, vui lòng thử lại sau ít phút." };
    if (err.status === 403) return { type: "message", message: "Tài khoản của bạn không thể đăng ký khóa học này." };
  }
  return { type: "message", message: "Không gửi được yêu cầu đăng ký, vui lòng thử lại." };
}
