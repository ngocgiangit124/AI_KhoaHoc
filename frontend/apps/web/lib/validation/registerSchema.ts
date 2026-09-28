import { z } from "zod";
import { calculateAgeYears } from "@/lib/age";
import { isValidVnPhone } from "./phone";

/** Khớp `RegisterRequest` (api-contract §2.2): `password (min 8, confirmed)`. */
export const MIN_PASSWORD_LENGTH = 8;

export interface RegisterSchemaOptions {
  /** `config/public.parent_consent_age` (mặc định 18 — api-contract §2.1). */
  parentConsentAge: number;
}

/**
 * Schema đăng ký (US-001 §2.1, US-017 AC1/AC10). 2 lỗi "gộp nhóm" (thiếu liên hệ phụ
 * huynh, thiếu 1 trong 2 checkbox đồng ý) được gắn vào field thật đầu tiên của nhóm
 * (`parent_phone`, `accept_terms`) để tương thích kiểu `FieldErrors` của react-hook-form —
 * UI chỉ hiển thị 1 dòng lỗi gộp bên dưới cả khối, không lặp lại theo từng field con.
 */
export function buildRegisterSchema({ parentConsentAge }: RegisterSchemaOptions) {
  return z
    .object({
      name: z
        .string()
        .trim()
        .min(1, "Vui lòng nhập họ và tên")
        .max(150, "Họ và tên tối đa 150 ký tự"),
      date_of_birth: z
        .string()
        .min(1, "Vui lòng chọn ngày sinh")
        .refine((v) => !Number.isNaN(new Date(v).getTime()), "Ngày sinh không hợp lệ")
        .refine((v) => new Date(v).getTime() <= Date.now(), "Ngày sinh không được ở tương lai"),
      // Cố ý KHÔNG dùng `.default("")`: `useForm<RegisterFormValues>` cần cùng 1 kiểu cho
      // cả input và output field values, còn `.default()` làm zod khai báo input là
      // `string | undefined`, gây lệch kiểu với react-hook-form (defaultValues đã đặt ""
      // sẵn ở RegisterForm.tsx).
      parent_phone: z.string().trim(),
      parent_email: z.string().trim(),
      email: z.string().trim().min(1, "Vui lòng nhập email").email("Email không đúng định dạng"),
      phone: z
        .string()
        .trim()
        .min(1, "Vui lòng nhập số điện thoại")
        .refine(isValidVnPhone, "Số điện thoại không đúng định dạng Việt Nam"),
      // Giữ dạng chuỗi (khớp giá trị `<select>`) để tránh phức tạp hoá kiểu dữ liệu giữa
      // input/output của zod khi dùng cùng react-hook-form; chuyển sang số khi build
      // payload gửi server (RegisterForm.tsx).
      grade_level: z
        .string()
        .min(1, "Vui lòng chọn lớp đang học")
        .refine((v) => {
          const n = Number(v);
          return Number.isInteger(n) && n >= 6 && n <= 12;
        }, "Lớp phải từ 6 đến 12"),
      password: z.string().min(MIN_PASSWORD_LENGTH, `Mật khẩu tối thiểu ${MIN_PASSWORD_LENGTH} ký tự`),
      password_confirmation: z.string().min(1, "Vui lòng xác nhận mật khẩu"),
      referral_code: z.string().trim(),
      accept_terms: z.boolean(),
      accept_privacy: z.boolean(),
      captcha_token: z.string().min(1, "Vui lòng hoàn tất xác minh chống spam"),
    })
    .superRefine((data, ctx) => {
      if (data.password !== data.password_confirmation) {
        ctx.addIssue({
          code: "custom",
          path: ["password_confirmation"],
          message: "Xác nhận mật khẩu không khớp",
        });
      }

      const age = calculateAgeYears(data.date_of_birth);
      const isMinor = age !== null && age < parentConsentAge;
      if (isMinor && !data.parent_phone && !data.parent_email) {
        ctx.addIssue({
          code: "custom",
          path: ["parent_phone"],
          message: "Vui lòng nhập ít nhất số điện thoại hoặc email phụ huynh",
        });
      }

      if (!data.accept_terms || !data.accept_privacy) {
        ctx.addIssue({
          code: "custom",
          path: ["accept_terms"],
          message: "Vui lòng đồng ý với cả điều khoản sử dụng và chính sách xử lý dữ liệu cá nhân",
        });
      }
    });
}

export type RegisterFormValues = z.infer<ReturnType<typeof buildRegisterSchema>>;
