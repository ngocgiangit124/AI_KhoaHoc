"use client";

import { useEffect, useState, type FormEvent } from "react";
import { ApiError, errorString } from "@vitaminvui/api-client";
import { Alert, Button, Field, PasswordInput, TextInput } from "@vitaminvui/ui/v2";
import { updateContact, type ContactPayload } from "@/lib/auth/api";
import { retryAfterText, UNKNOWN_ERROR_MESSAGE } from "@/lib/auth/errors";
import { buildContactPayload, normalizePhone } from "@/lib/auth/otp";
import { focusFirstError } from "@/lib/focus";
import { VN_PHONE_RE } from "@/lib/auth/schemas";

export interface ChangeContactFormProps {
  email: string;
  phone: string;
  /** Sau khi `PUT /auth/contact` thành công. `null` = server KHÔNG gửi mã mới (ví dụ chỉ đổi SĐT). */
  onDone: (result: { resendAvailableAt: string | null; emailChanged: boolean }) => void | Promise<void>;
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Đổi email/SĐT liên hệ (`PUT /auth/contact`, design-system-v2 §12.5). Chỉ gửi field thực sự thay đổi; BẮT BUỘC
 * `current_password` (Bảo mật cụm 1 H1): sai/thiếu -> lỗi dưới ô mật khẩu và xoá ô; sai nhiều lần (429) -> cảnh báo + khoá nút.
 * Đổi email: mã OTP cũ bị huỷ, mã mới gửi tới email mới, các thiết bị khác bị đăng xuất.
 */
export function ChangeContactForm({ email, phone, onDone }: ChangeContactFormProps) {
  const [values, setValues] = useState({ email, phone });
  const [errors, setErrors] = useState<{ email?: string; phone?: string; current_password?: string }>({});
  const [banner, setBanner] = useState<string | null>(null);
  const [throttle, setThrottle] = useState<string | null>(null);
  const [throttleMs, setThrottleMs] = useState<number | null>(null);
  const [currentPassword, setCurrentPassword] = useState("");
  const [pending, setPending] = useState(false);

  // Hết thời gian chờ thì mở khoá nút (mốc từ `Retry-After`).
  useEffect(() => {
    if (throttleMs === null) return;
    const id = setTimeout(() => {
      setThrottle(null);
      setThrottleMs(null);
    }, Math.min(throttleMs, 86_400_000));
    return () => clearTimeout(id);
  }, [throttleMs]);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (throttle) return;
    setBanner(null);

    const payload: ContactPayload = buildContactPayload({ email, phone }, values);
    const next: typeof errors = {};
    if (payload.email !== undefined && !EMAIL_RE.test(payload.email)) next.email = "Email không hợp lệ";
    if (payload.phone !== undefined && !VN_PHONE_RE.test(normalizePhone(payload.phone))) {
      next.phone = "Số điện thoại không hợp lệ";
    }
    if (Object.keys(payload).length === 0) {
      setErrors({});
      setBanner("Bạn chưa thay đổi email hoặc số điện thoại.");
      return;
    }
    if (!currentPassword) next.current_password = "Vui lòng nhập mật khẩu hiện tại để xác nhận thay đổi.";
    setErrors(next);
    if (Object.keys(next).length > 0) {
      focusFirstError([["contact-email", !!next.email], ["contact-phone", !!next.phone], ["contact-password", !!next.current_password]]);
      return;
    }

    setPending(true);
    try {
      const { resendAvailableAt } = await updateContact({ ...payload, current_password: currentPassword });
      setCurrentPassword("");
      await onDone({ resendAvailableAt, emailChanged: payload.email !== undefined });
    } catch (err) {
      setCurrentPassword(""); // không giữ mật khẩu sau khi lỗi — nhập lại
      if (err instanceof ApiError && err.status === 422 && err.errors) {
        const emailError = errorString(err, "email");
        const phoneError = errorString(err, "phone");
        const passwordError = errorString(err, "current_password");
        setErrors({ email: emailError, phone: phoneError, current_password: passwordError });
        focusFirstError([
          ["contact-email", !!emailError],
          ["contact-phone", !!phoneError],
          ["contact-password", !!passwordError],
        ]);
        if (!err.errors.email && !err.errors.phone && !err.errors.current_password) setBanner(err.message || UNKNOWN_ERROR_MESSAGE);
      } else if (err instanceof ApiError && err.status === 429) {
        // 429 còn đến từ trần gửi OTP / limiter `contact`, không chỉ sai mật khẩu: dùng câu chung.
        setThrottle(`Bạn đã thử quá nhiều lần. ${retryAfterText(err.retryAfterSeconds)}`);
        setThrottleMs((err.retryAfterSeconds ?? 60) * 1000);
      } else {
        setBanner(err instanceof Error && err.message ? err.message : UNKNOWN_ERROR_MESSAGE);
      }
    } finally {
      setPending(false);
    }
  }

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-5" aria-busy={pending}>
      {throttle ? (
        <Alert tone="warning" title="Bạn đã thử quá nhiều lần">
          {throttle} Trong thời gian này bạn cũng chưa đổi được mật khẩu.
        </Alert>
      ) : null}
      {banner ? <Alert tone="danger">{banner}</Alert> : null}
      <Field
        id="contact-email"
        label="Email"
        error={errors.email}
        hint="Đổi email: bạn cần xác thực lại bằng mã gửi tới email mới, và các thiết bị khác sẽ bị đăng xuất."
      >
        <TextInput type="email" autoComplete="email" value={values.email} disabled={pending} onChange={(e) => setValues((v) => ({ ...v, email: e.target.value }))} />
      </Field>
      <Field id="contact-phone" label="Số điện thoại" error={errors.phone}>
        <TextInput type="tel" inputMode="tel" autoComplete="tel" value={values.phone} disabled={pending} onChange={(e) => setValues((v) => ({ ...v, phone: e.target.value }))} />
      </Field>
      <Field id="contact-password" label="Mật khẩu hiện tại" required error={errors.current_password} hint="Để bảo vệ tài khoản, nhập mật khẩu hiện tại để xác nhận thay đổi.">
        <PasswordInput autoComplete="current-password" value={currentPassword} disabled={pending} onChange={(e) => setCurrentPassword(e.target.value)} />
      </Field>
      <div>
        <Button type="submit" loading={pending} loadingText="Đang lưu…" disabled={throttle !== null}>
          Lưu thay đổi
        </Button>
      </div>
    </form>
  );
}
