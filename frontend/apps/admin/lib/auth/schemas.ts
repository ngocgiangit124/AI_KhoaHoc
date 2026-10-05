import { z } from "zod";

export const loginSchema = z.object({
  login: z.string().trim().min(1, "Vui lòng nhập email"),
  password: z.string().min(1, "Vui lòng nhập mật khẩu"),
});
export type LoginValues = z.infer<typeof loginSchema>;

/** Giống học sinh: min 8, max 128 (api-contract "Bổ sung từ T03"). Laravel validate lại. */
export const passwordChangeSchema = z
  .object({
    current_password: z.string().min(1, "Vui lòng nhập mật khẩu hiện tại"),
    password: z.string().min(8, "Mật khẩu tối thiểu 8 ký tự").max(128, "Mật khẩu tối đa 128 ký tự"),
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
