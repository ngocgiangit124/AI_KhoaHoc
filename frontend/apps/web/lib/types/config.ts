import { z } from "zod";

/**
 * `GET /api/v1/config/public` — response phẳng (api-contract.md §1.4), ví dụ JSON đầy đủ
 * ở §2.1:
 * ```json
 * {
 *   "referral_code_enabled": true,
 *   "quiz_time_limit_enabled": true,
 *   "otp": { "ttl_minutes": 10, "resend_cooldown_seconds": 60 },
 *   "grades": [6, 7, 8, 9, 10, 11, 12],
 *   "captcha_site_key": null,
 *   "policy_version": "2026-09",
 *   "parent_consent_age": 18
 * }
 * ```
 * `captcha_site_key` = `null` khi chưa cấu hình Turnstile (local).
 */
export const publicConfigSchema = z.object({
  referral_code_enabled: z.boolean(),
  quiz_time_limit_enabled: z.boolean(),
  otp: z.object({
    ttl_minutes: z.number(),
    resend_cooldown_seconds: z.number(),
  }),
  grades: z.array(z.number()),
  captcha_site_key: z.string().nullable(),
  policy_version: z.string(),
  parent_consent_age: z.number(),
});

export type PublicConfig = z.infer<typeof publicConfigSchema>;

/**
 * Validate response `/config/public` lúc runtime (khớp api-contract, không chỉ tin type
 * TypeScript tĩnh). Backend trả sai hình dạng (đổi tên khoá, thiếu khoá...) → ném lỗi rõ
 * ràng, được `app/error.tsx` (Next.js error boundary) bắt và hiển thị Alert — xử lý NHƯ
 * lỗi API khác, không làm crash trắng trang.
 */
export function parsePublicConfig(data: unknown): PublicConfig {
  const result = publicConfigSchema.safeParse(data);

  if (!result.success) {
    if (process.env.NODE_ENV !== "production") {
      console.error("[config/public] response không khớp schema:", result.error.flatten());
    }
    throw new Error(
      "Không đọc được cấu hình công khai từ máy chủ (dữ liệu /config/public sai định dạng).",
    );
  }

  return result.data;
}
