import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { UNKNOWN_ERROR_MESSAGE } from "@/lib/auth/errors";
import { ContractError } from "./api";
import { adminStatus, formatDay, formatWhen, lastLogTo } from "./format";
import { conflictPayloadSchema, type ConflictPayload, type OrderDetail } from "./schemas";

export const FORBIDDEN_MESSAGE = "Bạn không có quyền xem hoặc xử lý đơn hàng (chỉ Admin và Quản lý trang).";
const GENERIC_API_MESSAGE = "Đã có lỗi xảy ra, vui lòng thử lại sau.";
export const NOT_FOUND_MESSAGE = "Không tìm thấy đơn hàng này.";

export type OrderAction = "approve" | "late" | "cancel" | "refund" | "note";
export type FieldKey = "reason" | "note" | "payment_reference" | "body" | "confirm";
const FIELD_KEYS: readonly FieldKey[] = ["reason", "note", "payment_reference", "body", "confirm"];

export type FailureKind = "validation" | "conflict" | "forbidden" | "not_found" | "throttled" | "network" | "other";

export interface ActionFailure {
  kind: FailureKind;
  status: number;
  code: string | null;
  message: string;
  fields: Partial<Record<FieldKey, string>>;
  /** 409: đóng hộp, tải lại đơn rồi báo bằng `conflictNotice` (không tự thử lại). */
  reload: boolean;
  payload: ConflictPayload | null;
}

const CONFLICT_CODES = new Set(["ALREADY_PROCESSED", "ORDER_STATUS_CHANGED", "ORDER_APPROVAL_WINDOW_PASSED", "COURSE_UNAVAILABLE", "ORDER_NOT_MANUAL"]);

export const retryText = (err: ApiError) => (err.retryAfterSeconds ? ` Vui lòng thử lại sau ${err.retryAfterSeconds} giây.` : " Vui lòng thử lại sau ít phút.");

/** Payload 409 nằm trong `ApiError.errors` (object, không phải string[]); đọc qua zod, sai dạng → null. */
export function conflictPayload(err: ApiError): ConflictPayload | null {
  const r = conflictPayloadSchema.safeParse(err.errors ?? {});
  return r.success ? r.data : null;
}

export function classifyActionError(err: unknown): ActionFailure {
  const base: ActionFailure = { kind: "other", status: 0, code: null, message: UNKNOWN_ERROR_MESSAGE, fields: {}, reload: false, payload: null };
  if (err instanceof NetworkError) return { ...base, kind: "network", message: err.message };
  if (err instanceof ContractError) return { ...base, message: err.message };
  if (!(err instanceof ApiError)) return base;
  const common = { ...base, status: err.status, code: err.code ?? null };
  if (err.status === 403) return { ...common, kind: "forbidden", message: FORBIDDEN_MESSAGE };
  if (err.status === 404) return { ...common, kind: "not_found", message: NOT_FOUND_MESSAGE };
  if (err.status === 429) return { ...common, kind: "throttled", message: `Bạn thao tác quá nhanh.${retryText(err)}` };
  if (err.status === 409 && err.code && CONFLICT_CODES.has(err.code)) {
    // Thông điệp tiếng Việt của server (vd "Tài khoản học sinh đã bị xoá nên không duyệt muộn được.") giữ nguyên; câu chung của api-client thì bỏ.
    return { ...common, kind: "conflict", reload: true, payload: conflictPayload(err), message: err.message === GENERIC_API_MESSAGE ? "" : err.message };
  }
  if (err.status === 422) {
    const fields: ActionFailure["fields"] = {};
    const others: string[] = [];
    for (const [key, msgs] of Object.entries(err.errors ?? {})) {
      const message = Array.isArray(msgs) ? msgs[0] : undefined;
      if (typeof message !== "string") continue;
      const field = key.replace(/\.\d+$/, "") as FieldKey;
      if (FIELD_KEYS.includes(field)) {
        if (!fields[field]) fields[field] = message;
      } else others.push(message);
    }
    const hasFields = Object.keys(fields).length > 0;
    return { ...common, kind: "validation", fields, message: others.join(" ") || (hasFields ? "" : err.message || UNKNOWN_ERROR_MESSAGE) };
  }
  return { ...common, message: err.message || UNKNOWN_ERROR_MESSAGE };
}

export interface Notice {
  tone: "success" | "info" | "warning" | "danger";
  title: string;
  body: string;
}

const cancelledBy = (order: OrderDetail): string => {
  const l = lastLogTo(order, "cancelled");
  if (!l) return "Đơn đã được huỷ.";
  if (l.reason === "user_cancelled") return `Học sinh đã tự huỷ đơn lúc ${formatWhen(l.created_at)}.`;
  if (l.reason === "expired") return `Đơn đã hết hạn chờ và tự huỷ lúc ${formatWhen(l.created_at)}.`;
  if (l.reason === "superseded") return `Học sinh đã đặt đơn mới thay cho đơn này lúc ${formatWhen(l.created_at)}.`;
  if (l.actor_type === "staff") return `${l.actor?.name ?? "Một Quản trị viên"} đã huỷ đơn lúc ${formatWhen(l.created_at)}.`;
  return `Đơn đã được huỷ lúc ${formatWhen(l.created_at)}.`;
};

const approvedBy = (order: OrderDetail): string => {
  const who = order.confirmed_by?.name ?? "Một Quản trị viên";
  return order.paid_at ? `${who} đã duyệt lúc ${formatWhen(order.paid_at)}.` : `${who} đã duyệt đơn.`;
};

/**
 * Thông báo đầu trang sau khi một thao tác bị 409. `order` là đơn VỪA TẢI LẠI (null nếu tải lại cũng lỗi → câu chung).
 * Không tự thử lại thao tác (AC19/AC21/AC22/AC24).
 */
export function conflictNotice(action: OrderAction, failure: ActionFailure, order: OrderDetail | null): Notice {
  const code = failure.code;
  const windowHint = order?.approval.can_approve_late ? " Nếu bạn đã nhận tiền cho đơn này, dùng “Duyệt muộn”." : "";
  if (code === "COURSE_UNAVAILABLE") {
    const names = (failure.payload?.courses ?? []).map((c) => `“${c.title}”`).join(", ");
    return {
      tone: "danger",
      title: "Không duyệt được: có khóa đã bị xoá",
      body: `${names ? `Khóa ${names} đã bị xoá` : "Có khóa trong đơn đã bị xoá"} nên không thể mở cho học sinh. Đơn giữ nguyên trạng thái. Nếu học sinh đã chuyển tiền, hãy hoàn tiền ngoài hệ thống và ghi chú lại.`,
    };
  }
  if (code === "ORDER_APPROVAL_WINDOW_PASSED") {
    const until = failure.payload?.approval_window_until ?? order?.approval.approval_window_until ?? null;
    return {
      tone: "danger",
      title: "Không duyệt muộn được",
      body: `${failure.message ? `${failure.message} ` : until ? `Đã quá hạn duyệt muộn (hạn ${formatDay(until)}). ` : ""}Nếu học sinh đã chuyển tiền, hãy hoàn tiền ngoài hệ thống và ghi chú lại.`.trim(),
    };
  }
  if (code === "ORDER_NOT_MANUAL") {
    return { tone: "danger", title: "Không xử lý được đơn này", body: failure.message || "Đơn này không thuộc phương thức Liên hệ Quản trị viên." };
  }
  if (!order) {
    return { tone: "warning", title: "Đơn vừa đổi trạng thái", body: "Có người khác vừa xử lý đơn này nhưng chưa tải lại được trạng thái mới. Hãy tải lại trang." };
  }
  if (code === "ALREADY_PROCESSED") {
    if (order.status === "paid") return { tone: "info", title: "Đơn đã được người khác xử lý", body: `${approvedBy(order)} Không cần thao tác thêm; học sinh chỉ được mở khóa một lần.` };
    if (order.status === "refunded") return { tone: "info", title: "Đơn đã được đánh dấu hoàn tiền", body: "Đơn này đã ở trạng thái hoàn tiền; không cần thao tác thêm." };
    if (order.status === "cancelled") return { tone: "info", title: "Đơn đã được huỷ", body: `${cancelledBy(order)} Không cần thao tác thêm.` };
  }
  // ORDER_STATUS_CHANGED (và ALREADY_PROCESSED lạ): mô tả trạng thái mới.
  const now = adminStatus(order.status, order.status_reason, order.payment_method).label;
  if (action === "approve" && order.status === "cancelled") {
    return { tone: "warning", title: "Chưa duyệt: đơn vừa đổi trạng thái", body: `${cancelledBy(order)} Trang đã tải lại trạng thái mới.${windowHint}` };
  }
  if (action === "approve" && order.status === "paid") {
    return { tone: "info", title: "Đơn đã được người khác xử lý", body: `${approvedBy(order)} Không cần thao tác thêm.` };
  }
  if (action === "cancel" && order.status === "paid") {
    return { tone: "info", title: "Không huỷ được: đơn vừa được duyệt", body: `${approvedBy(order)} Nếu cần thu hồi quyền học, dùng “Đánh dấu hoàn tiền”.` };
  }
  if (action === "refund") {
    return { tone: "warning", title: "Không hoàn tiền được", body: `Đơn đang ở trạng thái “${now}”, chỉ đơn đã duyệt mới đánh dấu hoàn tiền được.` };
  }
  if (action === "late") {
    return { tone: "warning", title: "Không duyệt muộn được", body: `Đơn đang ở trạng thái “${now}”, không còn là đơn đã huỷ.` };
  }
  return { tone: "warning", title: "Đơn vừa đổi trạng thái", body: `Trạng thái hiện tại: “${now}”. Trang đã tải lại.${windowHint}` };
}

/** Lỗi tải danh sách. */
export function listErrorMessage(err: unknown): string {
  if (err instanceof NetworkError) return err.message;
  if (err instanceof ContractError) return err.message;
  if (err instanceof ApiError) {
    if (err.status === 403) return FORBIDDEN_MESSAGE;
    if (err.status === 429) return `Bạn tìm kiếm hoặc thao tác quá nhanh (tìm theo email/SĐT giới hạn 30 lần/phút).${retryText(err)}`;
    if (err.status === 422) {
      if (err.errors?.["cursor"]) return "Liên kết trang không còn hợp lệ. Hãy quay lại trang đầu của danh sách.";
      const first = Object.values(err.errors ?? {}).find((m) => Array.isArray(m) && typeof m[0] === "string");
      return Array.isArray(first) && first[0] ? first[0] : "Bộ lọc không hợp lệ. Hãy xoá bộ lọc và thử lại.";
    }
    return err.message || UNKNOWN_ERROR_MESSAGE;
  }
  return UNKNOWN_ERROR_MESSAGE;
}

/** Lỗi tải chi tiết. */
export function detailErrorKind(err: unknown): "forbidden" | "not_found" | "other" {
  if (err instanceof ApiError && err.status === 403) return "forbidden";
  if (err instanceof ApiError && err.status === 404) return "not_found";
  return "other";
}
export const detailErrorMessage = listErrorMessage;
