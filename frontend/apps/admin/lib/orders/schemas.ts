import { z } from "zod";

/**
 * Hợp đồng `GET/POST /admin/orders*` (api-contract §2.5.1). Phản hồi được parse bằng zod để lệch hợp đồng lộ ra sớm
 * (thay vì render `undefined`). Mọi văn bản người dùng nhập (customer_note, notes[].body, refund_note, cancel_reason,
 * attempts[].result_message, tên học sinh/khóa) chỉ được render dạng TEXT (security T24-V1 S5).
 */
const nullable = <T extends z.ZodType>(s: T) => s.nullish().transform((v): z.output<T> | null => v ?? null);

export const ORDER_STATUSES = ["pending", "paid", "failed", "cancelled", "refunded"] as const;
export const orderStatusSchema = z.enum(ORDER_STATUSES);
export type OrderStatus = z.infer<typeof orderStatusSchema>;

export type PaymentMethod = "none" | "manual" | "momo";
const paymentMethodSchema = z.string();

const personSchema = z.object({ id: z.number(), name: z.string() });
export type Person = z.infer<typeof personSchema>;

export const orderListItemSchema = z.object({
  code: z.string(),
  status: orderStatusSchema,
  status_reason: nullable(z.string()),
  payment_method: paymentMethodSchema,
  items_count: z.number().default(0),
  first_item_title: nullable(z.string()),
  subtotal: z.number().default(0),
  discount: z.number().default(0),
  total: z.number(),
  needs_review: z.boolean().default(false),
  created_at: z.string(),
  expires_at: nullable(z.string()),
  expiring_soon: z.boolean().default(false),
  paid_at: nullable(z.string()),
  cancelled_at: nullable(z.string()),
  confirmed_by: nullable(personSchema),
  student: z.object({
    id: nullable(z.number()),
    name: z.string(),
    email_masked: nullable(z.string()),
    phone_masked: nullable(z.string()),
    is_deleted: z.boolean().default(false),
  }),
});
export type OrderListItem = z.infer<typeof orderListItemSchema>;

export const orderPageSchema = z.object({
  data: z.array(orderListItemSchema),
  meta: z.object({
    per_page: z.number().optional(),
    next_cursor: nullable(z.string()),
    prev_cursor: nullable(z.string()),
    total: z.number().default(0),
  }),
});
export type OrderPage = z.infer<typeof orderPageSchema>;

export const pendingCountSchema = z.object({
  pending_manual: z.number().int().nonnegative(),
  expiring_soon: z.number().int().nonnegative().default(0),
});
export type PendingCount = z.infer<typeof pendingCountSchema>;

export const REVIEW_REASONS = ["late_payment", "already_owned", "coupon_over_limit", "coupon_already_used", "course_unavailable"] as const;

export const approvalWarningSchema = z.object({
  code: z.string(),
  course_id: nullable(z.number()),
  title: nullable(z.string()),
  coupon_code: nullable(z.string()),
});
export type ApprovalWarning = z.infer<typeof approvalWarningSchema>;

const itemSchema = z.object({
  course_id: z.number(),
  title: z.string(),
  unit_price: z.number(),
  discount_amount: z.number().default(0),
  final_amount: z.number(),
  course_status: z.string().default("published"),
  current_price: nullable(z.number()),
});
export type OrderItem = z.infer<typeof itemSchema>;

const statusLogSchema = z.object({
  from: nullable(z.string()),
  to: z.string(),
  reason: nullable(z.string()),
  actor_type: z.string().default("system"),
  actor: nullable(personSchema),
  meta: z.record(z.string(), z.unknown()).nullish().transform((v) => v ?? {}),
  created_at: z.string(),
});
export type StatusLog = z.infer<typeof statusLogSchema>;

export const noteSchema = z.object({ id: z.number(), body: z.string(), author: nullable(personSchema), created_at: z.string() });
export type OrderNote = z.infer<typeof noteSchema>;

const attemptSchema = z.object({
  id: z.number(),
  gateway: z.string(),
  gateway_order_id: nullable(z.string()),
  amount: z.number(),
  status: z.string(),
  result_code: nullable(z.union([z.string(), z.number()])),
  result_message: nullable(z.string()),
  expires_at: nullable(z.string()),
  created_at: z.string(),
});
export type OrderAttempt = z.infer<typeof attemptSchema>;

export const orderDetailSchema = z.object({
  code: z.string(),
  status: orderStatusSchema,
  status_reason: nullable(z.string()),
  payment_method: paymentMethodSchema,
  needs_review: z.boolean().default(false),
  needs_review_reasons: z.array(z.string()).default([]),
  subtotal: z.number(),
  discount: z.number().default(0),
  total: z.number(),
  coupon_code: nullable(z.string()),
  payment_reference: nullable(z.string()),
  customer_note: nullable(z.string()),
  cancel_reason: nullable(z.string()),
  created_at: z.string(),
  expires_at: nullable(z.string()),
  paid_at: nullable(z.string()),
  cancelled_at: nullable(z.string()),
  refunded_at: nullable(z.string()),
  refund_note: nullable(z.string()),
  confirmed_by: nullable(personSchema),
  refunded_by: nullable(personSchema),
  student: z.object({
    id: nullable(z.number()),
    name: z.string(),
    email: nullable(z.string()),
    email_verified: z.boolean().default(false),
    phone: nullable(z.string()),
    phone_verified: z.boolean().default(false),
    account_status: z.string().default("active"),
    is_deleted: z.boolean().default(false),
  }),
  items: z.array(itemSchema),
  status_logs: z.array(statusLogSchema).default([]),
  notes: z.array(noteSchema).default([]),
  attempts: z.array(attemptSchema).default([]),
  approval: z.object({
    can_approve: z.boolean().default(false),
    can_approve_late: z.boolean().default(false),
    can_cancel: z.boolean().default(false),
    approval_window_until: nullable(z.string()),
    warnings: z.array(approvalWarningSchema).default([]),
    late_approval_warnings: z.array(approvalWarningSchema).default([]),
  }),
});
export type OrderDetail = z.infer<typeof orderDetailSchema>;

/** Payload 409 (`ApiError.errors` giữ nguyên object dù kiểu khai báo là `string[]`). */
export const conflictPayloadSchema = z.object({
  status: nullable(z.string()),
  status_reason: nullable(z.string()),
  cancelled_at: nullable(z.string()),
  can_approve_late: nullable(z.boolean()),
  approval_window_until: nullable(z.string()),
  courses: z.array(z.object({ id: z.number(), title: z.string() })).nullish().transform((v) => v ?? []),
});
export type ConflictPayload = z.infer<typeof conflictPayloadSchema>;

/* ---------- Form ---------- */
export const REF_MAX = 100;
export const NOTE_MAX = 1000;
export const REASON_MIN = 5;
export const REASON_MAX = 500;

export const approveFormSchema = z.object({
  received: z.literal(true, { error: "Tick xác nhận đã nhận đủ tiền." }),
  payment_reference: z.string().trim().max(REF_MAX, `Tối đa ${REF_MAX} ký tự.`),
  note: z.string().trim().max(NOTE_MAX, `Tối đa ${NOTE_MAX} ký tự.`),
});

export const cancelFormSchema = z.object({
  reason: z
    .string()
    .trim()
    .min(REASON_MIN, `Nhập lý do cho học sinh, ít nhất ${REASON_MIN} ký tự.`)
    .max(REASON_MAX, `Tối đa ${REASON_MAX} ký tự.`),
  note: z.string().trim().max(NOTE_MAX, `Tối đa ${NOTE_MAX} ký tự.`),
});

export const noteFormSchema = z.object({
  body: z.string().trim().min(1, "Nhập nội dung ghi chú.").max(NOTE_MAX, `Tối đa ${NOTE_MAX} ký tự.`),
});

export const refundFormSchema = z.object({
  confirmed: z.literal(true, { error: "Tick xác nhận đã hoàn tiền." }),
  note: z.string().trim().max(NOTE_MAX, `Tối đa ${NOTE_MAX} ký tự.`),
});

/** Lỗi đầu tiên của mỗi field. */
export function firstErrors(error: z.ZodError): Record<string, string> {
  const out: Record<string, string> = {};
  for (const issue of error.issues) {
    const key = String(issue.path[0] ?? "");
    if (key && !out[key]) out[key] = issue.message;
  }
  return out;
}
