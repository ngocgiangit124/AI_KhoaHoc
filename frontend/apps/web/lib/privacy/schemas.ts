import { z } from "zod";

/**
 * Hợp đồng API dữ liệu cá nhân (api-contract §2.8). Các enum mở (`type`, `granted_by`, `channel`) đọc bằng `z.string()`
 * để giá trị mới của backend không làm vỡ trang; nhãn tiếng Việt tra ở `format.ts` (lạ -> hiện nguyên giá trị).
 * KHÔNG có `ip`/`user_agent`: backend không trả (chỉ có trong file xuất) và FE không bao giờ hiển thị.
 */
export const consentSchema = z.object({
  type: z.string(),
  policy_version: z.string(),
  granted_by: z.string(),
  channel: z.string(),
  granted_at: z.string(),
  revoked_at: z.string().nullable(),
  is_current_version: z.boolean(),
});
export type Consent = z.infer<typeof consentSchema>;

export const consentsResponseSchema = z.object({
  data: z.array(consentSchema),
  meta: z.object({ current_policy_version: z.string(), needs_acceptance: z.boolean() }),
});
export type ConsentsResponse = z.infer<typeof consentsResponseSchema>;

export const parentContactSchema = z.object({
  email_masked: z.string().nullable(),
  phone_masked: z.string().nullable(),
  has_email: z.boolean(),
  has_phone: z.boolean(),
  notices_enabled: z.boolean(),
  notices_opted_out_at: z.string().nullable(),
});
export type ParentContact = z.infer<typeof parentContactSchema>;

export const dataExportStatusSchema = z.object({
  limit_per_day: z.number(),
  used_today: z.number(),
  remaining: z.number(),
  resets_at: z.string(),
  requires_password: z.boolean().optional(),
});
export type DataExportStatus = z.infer<typeof dataExportStatusSchema>;

export const deleteOtpResponseSchema = z.object({
  resend_available_at: z.string(),
  destination_masked: z.string(),
});
export type DeleteOtpResponse = z.infer<typeof deleteOtpResponseSchema>;
