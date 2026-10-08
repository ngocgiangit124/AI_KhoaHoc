import { z } from "zod";
import { ageOn, isValidIsoDate, todayInVietnam } from "./age";

/** SĐT di động VN: 0xxxxxxxxx hoặc +84xxxxxxxxx (validate thật ở Laravel). */
export const VN_PHONE_RE = /^(?:\+84|0)(?:3|5|7|8|9)\d{8}$/;

export const CONSENT_ERROR_MESSAGE =
  "Vui lòng đồng ý với cả điều khoản sử dụng và chính sách xử lý dữ liệu cá nhân";

const emailSchema = z.string().trim().min(1, "Vui lòng nhập email").max(255, "Email quá dài").pipe(z.email("Email không hợp lệ"));
const phoneSchema = z
  .string()
  .trim()
  .min(1, "Vui lòng nhập số điện thoại")
  .regex(VN_PHONE_RE, "Số điện thoại không hợp lệ");

export interface RegisterFormValues {
  name: string;
  date_of_birth: string;
  email: string;
  phone: string;
  grade_level: string;
  password: string;
  password_confirmation: string;
  parent_phone: string;
  parent_email: string;
  referral_code: string;
  accept_terms: boolean;
  accept_privacy: boolean;
}

export interface RegisterSchemaOptions {
  grades: readonly number[];
  /** Chỉ dùng cho test. */
  today?: string;
}

/** Schema đăng ký theo `RegisterRequest` (api-contract §2.2) — chỉ để UX, Laravel validate lại. */
export function createRegisterSchema({ grades, today }: RegisterSchemaOptions) {
  return z
    .object({
      name: z.string().trim().min(1, "Vui lòng nhập họ và tên").max(150, "Họ và tên tối đa 150 ký tự"),
      date_of_birth: z.string().min(1, "Vui lòng chọn ngày sinh"),
      email: emailSchema,
      phone: phoneSchema,
      grade_level: z
        .string()
        .min(1, "Vui lòng chọn lớp đang học")
        .refine((v) => grades.includes(Number(v)), "Lớp không hợp lệ"),
      password: z.string().min(8, "Mật khẩu tối thiểu 8 ký tự"),
      password_confirmation: z.string().min(1, "Vui lòng nhập lại mật khẩu"),
      parent_phone: z.string().trim(),
      parent_email: z.string().trim(),
      referral_code: z.string().trim().max(50, "Mã giới thiệu quá dài"),
      accept_terms: z.boolean(),
      accept_privacy: z.boolean(),
    })
    .superRefine((v, ctx) => {
      const todayStr = today ?? todayInVietnam();

      if (v.date_of_birth) {
        if (!isValidIsoDate(v.date_of_birth) || ageOn(v.date_of_birth, todayStr) === null) {
          ctx.addIssue({ code: "custom", path: ["date_of_birth"], message: "Ngày sinh không hợp lệ" });
        }
      }

      if (v.password_confirmation && v.password !== v.password_confirmation) {
        ctx.addIssue({ code: "custom", path: ["password_confirmation"], message: "Xác nhận mật khẩu không khớp" });
      }

      // Liên hệ phụ huynh TUỲ CHỌN ở mọi độ tuổi (ADR-006): chỉ kiểm định dạng khi có nhập.
      if (v.parent_phone && !VN_PHONE_RE.test(v.parent_phone)) {
        ctx.addIssue({ code: "custom", path: ["parent_phone"], message: "Số điện thoại phụ huynh không hợp lệ" });
      }
      if (v.parent_email && !z.email().safeParse(v.parent_email).success) {
        ctx.addIssue({ code: "custom", path: ["parent_email"], message: "Email phụ huynh không hợp lệ" });
      }

      if (!v.accept_terms || !v.accept_privacy) {
        ctx.addIssue({ code: "custom", path: ["accept_terms"], message: CONSENT_ERROR_MESSAGE });
      }
    });
}

export interface LoginFormValues {
  login: string;
  password: string;
}

export const loginSchema = z.object({
  login: z.string().trim().min(1, "Vui lòng nhập email hoặc số điện thoại"),
  password: z.string().min(1, "Vui lòng nhập mật khẩu"),
});
