import { authFetch } from "@/lib/api";
import { orderQueryToApi, type OrderQuery } from "./query";
import {
  noteSchema,
  orderDetailSchema,
  orderPageSchema,
  pendingCountSchema,
  type OrderDetail,
  type OrderNote,
  type OrderPage,
  type PendingCount,
} from "./schemas";

const BASE = "/api/v1/admin/orders";
const JSON_HEADERS = { "Content-Type": "application/json" };

/** Phản hồi sai hợp đồng (zod không parse được). Không phải lỗi người dùng — báo "lỗi dữ liệu". */
export class ContractError extends Error {
  constructor(what: string, cause?: unknown) {
    super(`Dữ liệu ${what} không đúng định dạng mong đợi.`);
    this.name = "ContractError";
    this.cause = cause;
  }
}

function parse<T>(schema: { safeParse: (v: unknown) => { success: true; data: T } | { success: false; error: unknown } }, raw: unknown, what: string): T {
  const r = schema.safeParse(raw);
  if (!r.success) throw new ContractError(what, r.error);
  return r.data;
}

const path = (code: string) => `${BASE}/${encodeURIComponent(code)}`;

export async function listOrders(query: OrderQuery, today: string, signal?: AbortSignal): Promise<OrderPage> {
  return parse(orderPageSchema, await authFetch<unknown>(`${BASE}?${orderQueryToApi(query, today).toString()}`, { signal }), "danh sách đơn");
}

/** Badge menu. Nguồn DUY NHẤT (không đặt trong /admin/auth/me). */
export async function getPendingCount(signal?: AbortSignal): Promise<PendingCount> {
  return parse(pendingCountSchema, await authFetch<unknown>(`${BASE}/pending-count`, { signal }), "số đơn chờ");
}

const inflight = new Map<string, Promise<OrderDetail>>();

/**
 * Chi tiết đơn. MỖI LẦN GỌI = 1 audit `order.view_pii` nên: không poll, và các lời gọi trùng đang bay cho cùng mã
 * (StrictMode chạy effect 2 lần ở dev) được gộp làm một. Không cache sau khi xong, không lưu storage (S5).
 */
export function getOrder(code: string): Promise<OrderDetail> {
  const existing = inflight.get(code);
  if (existing) return existing;
  const p = authFetch<unknown>(path(code))
    .then((raw) => parse(orderDetailSchema, raw, "đơn hàng"))
    .finally(() => inflight.delete(code));
  inflight.set(code, p);
  return p;
}

export interface ApproveInput {
  late: boolean;
  payment_reference: string;
  note: string;
}

/** `POST .../approve`. 200 = chi tiết đơn mới (không ghi thêm view_pii). */
export async function approveOrder(code: string, input: ApproveInput): Promise<OrderDetail> {
  const body: Record<string, unknown> = { confirm: true, late: input.late };
  if (input.payment_reference) body["payment_reference"] = input.payment_reference;
  if (input.note) body["note"] = input.note;
  return parse(orderDetailSchema, await authFetch<unknown>(`${path(code)}/approve`, { method: "POST", headers: JSON_HEADERS, body: JSON.stringify(body) }), "đơn hàng");
}

/** `POST .../cancel`: `reason` gửi học sinh (5–500); `note` nội bộ tuỳ chọn. */
export async function cancelOrder(code: string, input: { reason: string; note: string }): Promise<OrderDetail> {
  const body: Record<string, unknown> = { reason: input.reason };
  if (input.note) body["note"] = input.note;
  return parse(orderDetailSchema, await authFetch<unknown>(`${path(code)}/cancel`, { method: "POST", headers: JSON_HEADERS, body: JSON.stringify(body) }), "đơn hàng");
}

/** `POST .../notes` → 201 ghi chú mới. Không chống trùng ở server: UI khoá nút khi đang gửi. */
export async function addOrderNote(code: string, bodyText: string): Promise<OrderNote> {
  return parse(noteSchema, await authFetch<unknown>(`${path(code)}/notes`, { method: "POST", headers: JSON_HEADERS, body: JSON.stringify({ body: bodyText }) }), "ghi chú");
}

/** `POST .../refund` (US-010): `confirm: true`, `note` tuỳ chọn. */
export async function refundOrder(code: string, note: string): Promise<OrderDetail> {
  const body: Record<string, unknown> = { confirm: true };
  if (note) body["note"] = note;
  return parse(orderDetailSchema, await authFetch<unknown>(`${path(code)}/refund`, { method: "POST", headers: JSON_HEADERS, body: JSON.stringify(body) }), "đơn hàng");
}
