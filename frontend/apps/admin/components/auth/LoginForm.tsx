"use client";

import { useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Alert, Button, Field, PasswordInput, TextInput } from "@vitaminvui/ui/v2";
import { loginStaff } from "@/lib/auth/api";
import { loginErrorMessage, loginNotice } from "@/lib/auth/errors";
import { saveMfaHint, saveMfaResendAt } from "@/lib/auth/mfaHint";
import { loginSchema, zodFieldErrors } from "@/lib/auth/schemas";
import { safeNext } from "@/lib/nav";

export interface LoginFormProps {
  /** Giá trị thô của `?next=` — validate bằng `safeRedirect` (chống open redirect). */
  next?: string | null;
  /** `?reason=` (idle/expired/password_changed) — chỉ nhận giá trị trong allowlist. */
  reason?: string | null;
}

/** Form đăng nhập quản trị (US-016 §2.1): login → (MFA) → (đổi mật khẩu) → vào khu quản trị. */
export function LoginForm({ next, reason }: LoginFormProps) {
  const router = useRouter();
  const [login, setLogin] = useState("");
  const [password, setPassword] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const notice = loginNotice(reason);

  const target = safeNext(next);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (pending) return;
    setBanner(null);

    const parsed = loginSchema.safeParse({ login, password });
    if (!parsed.success) {
      setFieldErrors(zodFieldErrors(parsed.error));
      return;
    }
    setFieldErrors({});
    setPending(true);

    try {
      const result = await loginStaff(parsed.data);
      if (result.mfaRequired) {
        saveMfaHint(parsed.data.login);
        saveMfaResendAt(result.resendAvailableAt);
        router.replace(`/xac-thuc-mfa?next=${encodeURIComponent(target)}`);
      } else {
        // Khu quản trị tự dẫn sang /doi-mat-khau nếu API báo PASSWORD_CHANGE_REQUIRED.
        router.replace(target);
      }
      router.refresh();
    } catch (err) {
      setBanner(loginErrorMessage(err));
      setPassword("");
      setPending(false);
    }
  }

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-5" aria-busy={pending}>
      {notice ? <Alert tone={notice.variant}>{notice.message}</Alert> : null}
      {banner ? <Alert tone="danger">{banner}</Alert> : null}

      <fieldset disabled={pending} className="flex min-w-0 flex-col gap-4">
        <Field label="Email" required error={fieldErrors.login}>
          <TextInput
            name="login"
            type="email"
            autoComplete="username"
            value={login}
            onChange={(e) => setLogin(e.target.value)}
          />
        </Field>
        <Field label="Mật khẩu" required error={fieldErrors.password}>
          <PasswordInput
            name="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </Field>
      </fieldset>

      <Button type="submit" size="lg" block loading={pending} loadingText="Đang đăng nhập…">
        Đăng nhập
      </Button>
      <p className="text-center text-sm text-ink-soft">Quên mật khẩu? Liên hệ Admin để được đặt lại.</p>
    </form>
  );
}
