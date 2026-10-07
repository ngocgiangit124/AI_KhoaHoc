"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";
import { Alert, Button, ButtonLink, Field, TextInput } from "@vitaminvui/ui/v2";
import { routes } from "@/lib/v2/routes";

/**
 * POST /auth/password/forgot. Phản hồi luôn giống nhau (không lộ tài khoản có tồn tại hay không):
 * giữ nguyên `message` của server, rồi LUÔN sang bước 2 (`/quen-mat-khau/dat-lai`) dù tài khoản có tồn tại
 * hay không (US-015 §1). TODO(dev): Turnstile, `resend_available_at`, giữ `login` trong sessionStorage cho bước 2.
 */
export function ForgotPasswordForm() {
  const [sent, setSent] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string>();

  function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const login = String(new FormData(e.currentTarget).get("login") ?? "").trim();
    if (!login) {
      setError("Vui lòng nhập email hoặc số điện thoại.");
      return;
    }
    setError(undefined);
    setLoading(true);
    setTimeout(() => {
      setLoading(false);
      setSent(true);
    }, 800);
  }

  if (sent) {
    return (
      <div className="flex flex-col gap-5">
        <Alert tone="info" title="Nếu thông tin tồn tại, chúng tôi đã gửi mã xác nhận đến email của bạn.">
          Mã có hiệu lực 10 phút. Chỉ email đã xác thực mới nhận được mã.
        </Alert>
        <ButtonLink href={routes.resetPassword} size="lg" block>
          Nhập mã và đặt mật khẩu mới
        </ButtonLink>
        <Link href={routes.login} className="focus-ring w-fit rounded font-semibold text-primary hover:underline">
          Quay lại đăng nhập
        </Link>
      </div>
    );
  }

  return (
    <form noValidate onSubmit={onSubmit} className="flex flex-col gap-5">
      <Field label="Email hoặc số điện thoại" required error={error}>
        <TextInput name="login" autoComplete="username" />
      </Field>
      <p className="rounded-control border border-dashed border-line-strong px-3 py-2 text-sm text-ink-soft">Captcha Turnstile hiện ở đây (môi trường local chưa cấu hình nên ẩn).</p>
      <Button type="submit" size="lg" block loading={loading} loadingText="Đang gửi…">
        Gửi mã
      </Button>
      <Link href={routes.login} className="focus-ring w-fit rounded font-semibold text-primary hover:underline">
        Quay lại đăng nhập
      </Link>
    </form>
  );
}
