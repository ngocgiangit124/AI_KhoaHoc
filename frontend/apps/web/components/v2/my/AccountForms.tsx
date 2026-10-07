"use client";

import { useState, type FormEvent } from "react";
import { Alert, Button, Field, PasswordInput, TextInput, useToast } from "@vitaminvui/ui/v2";

/**
 * Đổi email/SĐT (PUT /auth/contact). Từ 2026-10-06 BẮT BUỘC `current_password` (Bảo mật cụm 1, H1).
 * - Sai/thiếu mật khẩu → lỗi ngay dưới ô "Mật khẩu hiện tại" (422 field current_password).
 * - Sai nhiều lần → 429: hiện Alert kèm thời gian chờ.
 * - Đổi email thành công → mã OTP gửi tới email mới, các thiết bị khác bị đăng xuất (báo trước trong mô tả).
 * TODO(dev): nối API; sau khi đổi email gọi lại /auth/me (cookie phiên được xoay).
 */
export type ContactDemoError = "sai-mat-khau" | "qua-nhieu" | "email-trung";

export function ChangeContactForm({ email, phone, demoError }: { email: string; phone: string; demoError?: ContactDemoError }) {
  const toast = useToast();
  const [loading, setLoading] = useState(false);
  const [err, setErr] = useState<string | undefined>(demoError === "sai-mat-khau" ? "Mật khẩu hiện tại không đúng." : undefined);
  // 422 field `email` (unique) — lỗi dưới ô email; mật khẩu hiện tại phải nhập lại (không giữ trong state).
  const emailErr = demoError === "email-trung" ? "Email đã được sử dụng." : undefined;
  const throttled = demoError === "qua-nhieu";

  function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const pw = String(new FormData(e.currentTarget).get("current_password") ?? "");
    if (!pw) {
      setErr("Vui lòng nhập mật khẩu hiện tại để xác nhận thay đổi.");
      (e.currentTarget.elements.namedItem("current_password") as HTMLInputElement | null)?.focus();
      return;
    }
    setErr(undefined);
    setLoading(true);
    setTimeout(() => {
      setLoading(false);
      toast.show({ tone: "success", title: "Đã lưu thay đổi", description: "Mã xác thực đã gửi tới email mới của bạn." });
    }, 900);
  }

  return (
    <form noValidate onSubmit={onSubmit} className="flex flex-col gap-5">
      {throttled ? (
        <Alert tone="warning" title="Bạn đã nhập sai mật khẩu nhiều lần">
          Vui lòng thử lại sau 15 phút. Trong thời gian này bạn cũng chưa đổi được mật khẩu.
        </Alert>
      ) : null}
      <Field label="Email" error={emailErr} hint="Đổi email: bạn cần xác thực lại bằng mã gửi tới email mới, và các thiết bị khác sẽ bị đăng xuất.">
        <TextInput name="email" type="email" defaultValue={demoError === "email-trung" ? "hoa.tran@gmail.com" : email} autoComplete="email" />
      </Field>
      <Field label="Số điện thoại">
        <TextInput name="phone" type="tel" defaultValue={phone} autoComplete="tel" />
      </Field>
      <Field label="Mật khẩu hiện tại" required error={err} hint="Để bảo vệ tài khoản, nhập mật khẩu hiện tại để xác nhận thay đổi.">
        <PasswordInput name="current_password" autoComplete="current-password" />
      </Field>
      <div>
        <Button type="submit" loading={loading} loadingText="Đang lưu…" disabled={throttled}>
          Lưu thay đổi
        </Button>
      </div>
    </form>
  );
}

/** Đổi mật khẩu (PUT /auth/password): min 8, không trùng mật khẩu cũ, chặn mật khẩu phổ biến. */
export function ChangePasswordForm() {
  const toast = useToast();
  const [loading, setLoading] = useState(false);
  const [errors, setErrors] = useState<{ current?: string; password?: string; confirm?: string }>({});

  function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    const cur = String(f.get("current_password") ?? "");
    const pw = String(f.get("password") ?? "");
    const cf = String(f.get("password_confirmation") ?? "");
    const next: typeof errors = {};
    if (!cur) next.current = "Vui lòng nhập mật khẩu hiện tại.";
    if (pw.length < 8) next.password = "Mật khẩu mới cần tối thiểu 8 ký tự.";
    else if (["12345678", "password123", "matkhau123"].includes(pw)) next.password = "Mật khẩu quá phổ biến, dễ bị đoán. Vui lòng chọn mật khẩu khác.";
    if (cf !== pw) next.confirm = "Xác nhận mật khẩu không khớp.";
    setErrors(next);
    if (Object.keys(next).length) return;
    setLoading(true);
    setTimeout(() => {
      setLoading(false);
      toast.show({ tone: "success", title: "Đã đổi mật khẩu", description: "Các thiết bị khác đã được đăng xuất." });
    }, 900);
  }

  return (
    <form noValidate onSubmit={onSubmit} className="flex flex-col gap-5">
      <Field label="Mật khẩu hiện tại" required error={errors.current}>
        <PasswordInput name="current_password" autoComplete="current-password" />
      </Field>
      <Field label="Mật khẩu mới" required error={errors.password} hint="Tối thiểu 8 ký tự, khác mật khẩu hiện tại.">
        <PasswordInput name="password" autoComplete="new-password" maxLength={128} />
      </Field>
      <Field label="Nhập lại mật khẩu mới" required error={errors.confirm}>
        <PasswordInput name="password_confirmation" autoComplete="new-password" maxLength={128} />
      </Field>
      <div>
        <Button type="submit" loading={loading} loadingText="Đang đổi…">
          Đổi mật khẩu
        </Button>
      </div>
    </form>
  );
}
