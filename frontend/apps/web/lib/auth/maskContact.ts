/**
 * Che một phần email/SĐT để hiển thị ở màn xác thực OTP (US-001 §2.2 mockup: "gửi mã gồm 6
 * chữ số tới min***@gmail.com"). Đây CHỈ là hiển thị UI cho chính chủ tài khoản (không phải
 * biện pháp bảo mật che PII người khác như `parent_*` ở `/auth/me` — MeResource đã che ở
 * server, xem `docs/security/review-T03-FW1.md` L4) — dữ liệu vào hàm này lấy từ field
 * `email`/`phone` (không phải `parent_email`/`parent_phone`) của chính học sinh.
 */
export function maskEmail(email: string): string {
  const atIndex = email.indexOf("@");
  if (atIndex <= 0) return email;
  const local = email.slice(0, atIndex);
  const domain = email.slice(atIndex);
  const visible = local.slice(0, Math.min(3, local.length));
  return `${visible}***${domain}`;
}

export function maskPhone(phone: string): string {
  const digits = phone.replace(/\D/g, "");
  if (digits.length < 7) return phone;
  return `${digits.slice(0, 3)}****${digits.slice(-3)}`;
}
