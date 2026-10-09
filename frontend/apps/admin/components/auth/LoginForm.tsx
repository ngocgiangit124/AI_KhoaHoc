"use client";

import { useEffect, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { isStaleDocument, loginNeedsCaptcha } from "@vitaminvui/api-client";
import { Alert, Button, Field, PasswordInput, TextInput } from "@vitaminvui/ui/v2";
import { TurnstileWidget } from "@vitaminvui/ui";
import { loginStaff } from "@/lib/auth/api";
import { CAPTCHA_REQUIRED_MESSAGE, loginErrorMessage, loginNotice } from "@/lib/auth/errors";
import { saveMfaHint, saveMfaResendAt } from "@/lib/auth/mfaHint";
import { loginSchema, zodFieldErrors } from "@/lib/auth/schemas";
import { safeNext } from "@/lib/nav";

/** Sau khi tải lại tài liệu (CSP đúng) chỉ khôi phục định danh đã nhập (KHÔNG mật khẩu, KHÔNG token) và cờ "đang bị đòi captcha". */
const RELOAD_KEY = "vv:gla2-login";
/** Widget không bắn callback trong chừng này thì coi như không tải được (CSP cũ, chặn mạng) và gợi ý tải lại trang. */
const WIDGET_TIMEOUT_MS = 10_000;

export interface LoginFormProps {
  /** Giá trị thô của `?next=` — validate bằng `safeRedirect` (chống open redirect). */
  next?: string | null;
  /** `?reason=` (idle/expired/password_changed) — chỉ nhận giá trị trong allowlist. */
  reason?: string | null;
  /** Site key Turnstile (GL-A2); mặc định lấy từ `NEXT_PUBLIC_TURNSTILE_SITE_KEY`. Rỗng = không hiện widget. */
  captchaSiteKey?: string | null;
}

/** Form đăng nhập quản trị (US-016 §2.1): login → (MFA) → (đổi mật khẩu) → vào khu quản trị. */
export function LoginForm({ next, reason, captchaSiteKey = process.env.NEXT_PUBLIC_TURNSTILE_SITE_KEY ?? null }: LoginFormProps) {
  const router = useRouter();
  const [login, setLogin] = useState("");
  const [password, setPassword] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  // GL-A2: sau khi sai nhiều lần server đòi captcha; token dùng 1 lần nên đổi `captchaKey` để mount widget mới sau MỖI lần gửi.
  const [captchaNeeded, setCaptchaNeeded] = useState(false);
  const [captchaToken, setCaptchaToken] = useState<string | null>(null);
  const [captchaKey, setCaptchaKey] = useState(0);
  const [captchaBroken, setCaptchaBroken] = useState(false);
  const siteKey = captchaSiteKey && captchaSiteKey.trim() !== "" ? captchaSiteKey : null;
  const showWidget = captchaNeeded && siteKey !== null;
  const notice = loginNotice(reason);

  // Quay lại sau khi tải lại tài liệu vì CSP cũ: khôi phục email, hiện luôn widget.
  useEffect(() => {
    let saved: string | null = null;
    try {
      saved = sessionStorage.getItem(RELOAD_KEY);
      sessionStorage.removeItem(RELOAD_KEY);
    } catch {
      /* storage bị chặn: bỏ qua */
    }
    if (saved === null) return;
    // eslint-disable-next-line react-hooks/set-state-in-effect -- khôi phục trạng thái sau reload, chỉ chạy 1 lần khi mount
    setCaptchaNeeded(true);
    setBanner(CAPTCHA_REQUIRED_MESSAGE);
    if (saved) setLogin(saved);
  }, []);

  // Widget không bắn callback (script/iframe bị chặn): sau 10 giây gợi ý tải lại trang.
  useEffect(() => {
    if (!showWidget || captchaToken !== null || captchaBroken) return;
    const t = setTimeout(() => setCaptchaBroken(true), WIDGET_TIMEOUT_MS);
    return () => clearTimeout(t);
  }, [showWidget, captchaToken, captchaBroken, captchaKey]);

  function onCaptchaToken(token: string | null) {
    setCaptchaToken(token);
    if (token) setCaptchaBroken(false); // widget tự thử lại thành công
  }

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
      const result = await loginStaff({ ...parsed.data, captchaToken: showWidget ? captchaToken : null });
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
      // Trang đến bằng điều hướng mềm thì CSP là của trang trước, iframe Turnstile bị chặn: tải lại tài liệu (giữ email).
      if (loginNeedsCaptcha(err) && isStaleDocument()) {
        try {
          sessionStorage.setItem(RELOAD_KEY, parsed.data.login);
        } catch {
          /* bỏ qua */
        }
        window.location.reload();
        return;
      }
      setBanner(loginErrorMessage(err));
      setPassword("");
      if (loginNeedsCaptcha(err)) setCaptchaNeeded(true);
      // Token đã dùng (hoặc bị từ chối): bỏ và lấy token mới.
      setCaptchaToken(null);
      setCaptchaBroken(false);
      setCaptchaKey((k) => k + 1);
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

      {showWidget && siteKey ? (
        <div className="flex flex-col gap-2">
          <TurnstileWidget key={captchaKey} siteKey={siteKey} onToken={onCaptchaToken} onError={() => setCaptchaBroken(true)} />
          {captchaBroken ? (
            <Alert tone="danger" role="alert" title="Không tải được bước xác minh chống spam.">
              Hãy{" "}
              <button type="button" onClick={() => window.location.reload()} className="focus-ring rounded font-semibold underline">
                tải lại trang
              </button>{" "}
              rồi thử lại.
            </Alert>
          ) : null}
          <p id="captcha-hint" role="status" className="text-sm text-ink-soft">
            {captchaToken === null ? "Vui lòng hoàn tất xác minh chống spam để đăng nhập." : ""}
          </p>
        </div>
      ) : captchaNeeded ? (
        <Alert tone="warning">Chưa thể xác minh chống spam lúc này. Vui lòng thử lại sau ít phút hoặc liên hệ Admin.</Alert>
      ) : null}

      <Button type="submit" size="lg" block loading={pending} loadingText="Đang đăng nhập…" disabled={showWidget && captchaToken === null} aria-describedby={showWidget && captchaToken === null ? "captcha-hint" : undefined}>
        Đăng nhập
      </Button>
      <p className="text-center text-sm text-ink-soft">Quên mật khẩu? Liên hệ Admin để được đặt lại.</p>
    </form>
  );
}
