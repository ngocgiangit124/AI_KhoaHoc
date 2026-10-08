import { normalizePhone } from "@/lib/auth/otp";
import { VN_PHONE_RE } from "@/lib/auth/schemas";
import type { ParentContactUpdate } from "./api";

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export interface ParentContactInput {
  email: string;
  phone: string;
  /** Bấm "Xoá" ở ô: gửi `null` cho field đó. */
  removeEmail: boolean;
  removePhone: boolean;
  password: string;
}

export interface ParentContactErrors {
  parent_email?: string;
  parent_phone?: string;
  current_password?: string;
  form?: string;
}

/**
 * Dựng body `PUT /me/parent-contact` (api-contract §2.8.2): ô để trống = bỏ key (giữ nguyên) · "Xoá" = `null` · có nhập = thay.
 * Phải có ít nhất một thay đổi và mật khẩu hiện tại. Định dạng kiểm sơ bộ; server mới là nơi quyết định (422 theo field).
 */
export function buildParentContactBody(input: ParentContactInput): { body: ParentContactUpdate | null; errors: ParentContactErrors } {
  const errors: ParentContactErrors = {};
  const body: ParentContactUpdate = { current_password: input.password };
  const email = input.email.trim();
  const phone = input.phone.trim();

  if (input.removeEmail) body.parent_email = null;
  else if (email) {
    if (!EMAIL_RE.test(email) || email.length > 254) errors.parent_email = "Email không hợp lệ";
    else body.parent_email = email;
  }
  if (input.removePhone) body.parent_phone = null;
  else if (phone) {
    if (!VN_PHONE_RE.test(normalizePhone(phone))) errors.parent_phone = "Số điện thoại không hợp lệ";
    else body.parent_phone = phone;
  }

  if (!("parent_email" in body) && !("parent_phone" in body) && !errors.parent_email && !errors.parent_phone) {
    errors.form = "Vui lòng nhập thông tin cần cập nhật.";
  }
  if (!input.password) errors.current_password = "Vui lòng nhập mật khẩu hiện tại để xác nhận thay đổi.";

  return { body: Object.keys(errors).length > 0 ? null : body, errors };
}
