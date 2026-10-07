"use client";

import { useState, type FormEvent } from "react";
import { ApiError } from "@vitaminvui/api-client";
import { Alert, Button, FormField, PasswordInput, TextInput } from "@vitaminvui/ui";
import { updateContact, type ContactPayload } from "@/lib/auth/api";
import { UNKNOWN_ERROR_MESSAGE } from "@/lib/auth/errors";
import { VN_PHONE_RE } from "@/lib/auth/schemas";
import { buildContactPayload, normalizePhone } from "@/lib/auth/otp";

export interface ChangeContactFormProps {
  email: string;
  phone: string;
  onCancel: () => void;
  /** Sau khi `PUT /auth/contact` thành công. `null` = server KHÔNG gửi mã mới (ví dụ chỉ đổi SĐT). */
  onDone: (resendAvailableAt: string | null) => void | Promise<void>;
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** Đổi email/SĐT liên hệ khi chưa xác thực (`PUT /auth/contact`). Chỉ gửi field thực sự thay đổi. */
export function ChangeContactForm({ email, phone, onCancel, onDone }: ChangeContactFormProps) {
  const [values, setValues] = useState({ email, phone });
  const [errors, setErrors] = useState<{ email?: string; phone?: string; current_password?: string }>({});
  const [banner, setBanner] = useState<string | null>(null);
  const [currentPassword, setCurrentPassword] = useState("");
  const [pending, setPending] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setBanner(null);

    const payload: ContactPayload = buildContactPayload({ email, phone }, values);
    const next: typeof errors = {};
    if (payload.email !== undefined && !EMAIL_RE.test(payload.email)) next.email = "Email không hợp lệ";
    if (payload.phone !== undefined && !VN_PHONE_RE.test(normalizePhone(payload.phone))) {
      next.phone = "Số điện thoại không hợp lệ";
    }
    if (Object.keys(payload).length === 0) {
      setBanner("Bạn chưa thay đổi email hoặc số điện thoại.");
      return;
    }
    if (!currentPassword) next.current_password = "Vui lòng nhập mật khẩu hiện tại để xác nhận thay đổi.";
    setErrors(next);
    if (Object.keys(next).length > 0) return;

    setPending(true);
    try {
      const { resendAvailableAt } = await updateContact({ ...payload, current_password: currentPassword });
      await onDone(resendAvailableAt);
    } catch (err) {
      setCurrentPassword(""); // không giữ mật khẩu sau khi lỗi — nhập lại
      if (err instanceof ApiError && err.status === 422 && err.errors) {
        setErrors({ email: err.errors.email?.[0], phone: err.errors.phone?.[0], current_password: err.errors.current_password?.[0] });
        if (!err.errors.email && !err.errors.phone && !err.errors.current_password) setBanner(err.message || UNKNOWN_ERROR_MESSAGE);
      } else if (err instanceof ApiError && err.status === 429) {
        const wait = err.retryAfterSeconds ? ` Vui lòng thử lại sau ${Math.ceil(err.retryAfterSeconds / 60)} phút.` : " Vui lòng thử lại sau ít phút.";
        setBanner(`Bạn đã nhập sai mật khẩu quá nhiều lần.${wait}`);
      } else {
        setBanner(err instanceof Error && err.message ? err.message : UNKNOWN_ERROR_MESSAGE);
      }
      setPending(false);
    }
  }

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4">
      <p className="text-sm text-gray-700">
        Nhập thông tin liên hệ mới. Khi đổi email, mã xác thực cũ sẽ bị huỷ và chúng tôi gửi mã mới tới email mới.
      </p>
      {banner ? <Alert variant="danger">{banner}</Alert> : null}
      <FormField label="Email" error={errors.email}>
        <TextInput
          type="email"
          autoComplete="email"
          value={values.email}
          disabled={pending}
          onChange={(e) => setValues((v) => ({ ...v, email: e.target.value }))}
        />
      </FormField>
      <FormField label="Số điện thoại" error={errors.phone}>
        <TextInput
          type="tel"
          autoComplete="tel"
          value={values.phone}
          disabled={pending}
          onChange={(e) => setValues((v) => ({ ...v, phone: e.target.value }))}
        />
      </FormField>
      <FormField label="Mật khẩu hiện tại" required error={errors.current_password} hint="Để bảo vệ tài khoản, nhập mật khẩu hiện tại để xác nhận thay đổi.">
        <PasswordInput
          autoComplete="current-password"
          value={currentPassword}
          disabled={pending}
          onChange={(e) => setCurrentPassword(e.target.value)}
        />
      </FormField>
      <div className="flex gap-3">
        <Button type="submit" loading={pending}>
          Lưu và gửi mã mới
        </Button>
        <Button type="button" variant="outline" disabled={pending} onClick={onCancel}>
          Huỷ
        </Button>
      </div>
    </form>
  );
}
