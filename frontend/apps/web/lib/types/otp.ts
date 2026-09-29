import { z } from "zod";

/**
 * `POST /auth/otp/send` trả 202 + `resend_available_at` (api-contract §2.2). Đối chiếu T04
 * thật (`OtpController::send()`): `resend_available_at` là chuỗi ISO 8601 có offset
 * (`Carbon::toIso8601String()`), đúng như giả định ban đầu. Dùng `.passthrough()` để không vỡ
 * khi backend trả thêm field.
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
