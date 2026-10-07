import { z } from "zod";

export const loginSchema = z.object({
  login: z.string().trim().min(1, "Vui lòng nhập email"),
  password: z.string().min(1, "Vui lòng nhập mật khẩu"),
});
export type LoginValues = z.infer<typeof loginSchema>;

/** Mật khẩu staff tối thiểu 12 ký tự (Bảo mật cụm 1, 2026-10-06); tối đa 128. Laravel validate lại (mật khẩu phổ biến,
 * chứa phần trước @ của email → 422 `errors.password`, hiện dưới ô qua `classifyPasswordError`). */
export const STAFF_PASSWORD_MIN_LENGTH = 12;

export const passwordChangeSchema = z
  .object({
    current_password: z.string().min(1, "Vui lòng nhập mật khẩu hiện tại"),
    password: z.string().min(STAFF_PASSWORD_MIN_LENGTH, `Mật khẩu tối thiểu ${STAFF_PASSWORD_MIN_LENGTH} ký tự`).max(128, "Mật khẩu tối đa 128 ký tự"),
    password_confirmation: z.string().min(1, "Vui lòng nhập lại mật khẩu mới"),
  })
  .superRefine((v, ctx) => {
    if (v.password && v.password === v.current_password) {
      ctx.addIssue({ code: "custom", path: ["password"], message: "Mật khẩu mới phải khác mật khẩu hiện tại" });
    }
    if (v.password_confirmation && v.password !== v.password_confirmation) {
      ctx.addIssue({ code: "custom", path: ["password_confirmation"], message: "Xác nhận mật khẩu không khớp" });
    }
  });
export type PasswordChangeValues = z.infer<typeof passwordChangeSchema>;

/** Lấy thông điệp đầu tiên của mỗi field từ lỗi zod. */
export function zodFieldErrors(error: z.ZodError): Record<string, string> {
  const out: Record<string, string> = {};
  for (const issue of error.issues) {
    const key = String(issue.path[0] ?? "");
    if (key && !out[key]) out[key] = issue.message;
  }
  return out;
}
