import { z } from "zod";

/**
 * `POST /auth/otp/send` trả 202 + `resend_available_at` (api-contract §2.2). GIẢ ĐỊNH (ghi
 * rõ cho laravel-dev đối chiếu khi T04 xong — api-contract chưa cho ví dụ JSON cụ thể):
 * `resend_available_at` là chuỗi ISO 8601 (giống quy ước timestamp khác của contract, ví dụ
 * `expires_at` ở §2.3/§2.4). Dùng `.passthrough()` để không vỡ khi backend trả thêm field.
 */
export const otpSendResponseSchema = z
  .object({
    resend_available_at: z.string(),
  })
  .passthrough();

export type OtpSendResponse = z.infer<typeof otpSendResponseSchema>;

export function parseOtpSendResponse(data: unknown): OtpSendResponse {
  const result = otpSendResponseSchema.safeParse(data);
  if (!result.success) {
    if (process.env.NODE_ENV !== "production") {
      console.error("[auth/otp/send] response không khớp schema:", result.error.flatten());
    }
    throw new Error("Không đọc được thời gian có thể gửi lại mã từ máy chủ.");
  }
  return result.data;
}
