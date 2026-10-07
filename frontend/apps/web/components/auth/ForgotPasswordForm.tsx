"use client";

import { useState, type FormEvent } from "react";
import { TurnstileWidget } from "@vitaminvui/ui";
import { Alert, Button, Field, TextInput } from "@vitaminvui/ui/v2";
import { AppLink } from "@/components/shell/AppLink";
import { focusFirstError } from "@/lib/focus";
import { forgotPassword } from "@/lib/auth/api";
import { classifyForgotError, CAPTCHA_FAILED_MESSAGE } from "@/lib/auth/errors";
import { setResetLogin } from "@/lib/auth/flash";
import { routes } from "@/lib/routes";

export interface ForgotPasswordFormProps {
  /** `null` khi chưa cấu hình Turnstile (local) — bỏ qua widget, không gửi `captcha_token`. */
  captchaSiteKey: string | null;
}

/**
 * Bước 1 quên mật khẩu (`POST /auth/password/forgot`, US-015 §2.1). Phản hồi luôn giống nhau dù tài khoản có tồn tại, bị khoá hay
 * chưa xác thực email hay không (không lộ gì): luôn chuyển thẳng sang bước 2 (thông điệp chung nằm ở phụ đề bước 2). `login` giữ
 * trong sessionStorage (không đặt lên URL).
 */
export function ForgotPasswordForm({ captchaSiteKey }: ForgotPasswordFormProps) {
  const [login, setLogin] = useState("");
  const [fieldError, setFieldError] = useState<string>();
  const [banner, setBanner] = useState<{ tone: "danger" | "warning"; text: string } | null>(null);
  const [sent, setSent] = useState(false);
  const [pending, setPending] = useState(false);
  const [captchaToken, setCaptchaToken] = useState<string | null>(null);
  const [captchaKey, setCaptchaKey] = useState(0);

  const captchaEnabled = typeof captchaSiteKey === "string" && captchaSiteKey.trim() !== "";
  const captchaPending = captchaEnabled && captchaToken === null;

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (pending) return;
    if (!login.trim()) {
      setFieldError("Vui lòng nhập email hoặc số điện thoại.");
      focusFirstError([["forgot-login", true]]);
      return;
    }
    setFieldError(undefined);
    setBanner(null);
    setPending(true);
    try {
      const result = await forgotPassword({ login, captchaToken });
      setResetLogin(login.trim(), result.resendAvailableAt);
      setSent(true);
      // Luôn sang bước 2 (design §12.8; không lộ tài khoản). Điều hướng CỨNG: bước 2 cần CSP có Cloudflare cho Turnstile ẩn.
      window.location.assign(routes.resetPassword);
      return;
    } catch (err) {
      const failure = classifyForgotError(err);
      if (failure.kind === "field") {
        setFieldError(failure.message);
        focusFirstError([["forgot-login", true]]);
      } else if (failure.kind === "captcha") setBanner({ tone: "danger", text: failure.message });
      else setBanner({ tone: failure.kind === "throttled" ? "warning" : "danger", text: failure.message });
    } finally {
      setPending(false);
      // Token Turnstile dùng 1 lần.
      setCaptchaToken(null);
      setCaptchaKey((k) => k + 1);
    }
  }

  return (
    <form noValidate onSubmit={onSubmit} className="flex flex-col gap-5" aria-busy={pending}>
      {banner ? (
        <Alert tone={banner.tone} role="alert">
          {banner.text}
        </Alert>
      ) : null}
      <Field id="forgot-login" label="Email hoặc số điện thoại" required error={fieldError}>
        <TextInput autoComplete="username" value={login} disabled={pending} onChange={(e) => setLogin(e.target.value)} />
      </Field>
      {captchaEnabled && captchaSiteKey ? (
        <div className="flex flex-col gap-2">
          <TurnstileWidget
            key={captchaKey}
            siteKey={captchaSiteKey}
            onToken={setCaptchaToken}
            onError={() => setBanner({ tone: "danger", text: CAPTCHA_FAILED_MESSAGE })}
          />
          {captchaPending ? <p className="text-sm text-ink-soft">Vui lòng hoàn tất xác minh chống spam để gửi mã.</p> : null}
        </div>
      ) : null}
      <Button type="submit" size="lg" block loading={pending || sent} loadingText="Đang gửi…" disabled={captchaPending}>
        Gửi mã
      </Button>
      <AppLink href={routes.login} className="focus-ring inline-flex min-h-11 w-fit items-center rounded font-semibold text-primary hover:underline">
        Quay lại đăng nhập
      </AppLink>
    </form>
  );
}
