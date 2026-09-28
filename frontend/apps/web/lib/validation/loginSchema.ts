import { z } from "zod";

/** Khớp `LoginRequest` (api-contract §2.2): `login` (email hoặc SĐT) + `password`. */
export const loginSchema = z.object({
  login: z.string().trim().min(1, "Vui lòng nhập email hoặc số điện thoại"),
  password: z.string().min(1, "Vui lòng nhập mật khẩu"),
});

export type LoginFormValues = z.infer<typeof loginSchema>;
