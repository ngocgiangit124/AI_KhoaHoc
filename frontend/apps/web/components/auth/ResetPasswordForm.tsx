"use client";

import { useEffect, useState, useSyncExternalStore, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { AppLink } from "@/components/shell/AppLink";
import { focusFirstError } from "@/lib/focus";
import { TurnstileWidget } from "@vitaminvui/ui";
import { Alert, Button, ButtonLink, Field, OtpInput, PasswordInput, ResendCode } from "@vitaminvui/ui/v2";
import { forgotPassword, resetPassword } from "@/lib/auth/api";
import {
  CAPTCHA_FAILED_MESSAGE,
  RESET_CODE_MESSAGE,
  classifyForgotError,
  classifyResetError,
} from "@/lib/auth/errors";
import { readResetLogin, readResetResendAt } from "@/lib/auth/flash";
import { OTP_LENGTH, secondsUntil } from "@/lib/auth/otp";
import { routes } from "@/lib/routes";

export interface ResetPasswordFormProps {
  /** `otp.resend_cooldown_seconds` từ `/config/public` — chỉ dùng khi bước 1 không để lại `resend_available_at`. */
  resendCooldownSeconds: number;
  /** `null` khi chưa cấu hình Turnstile (local) — bỏ qua widget, không gửi `captcha_token`. */
  captchaSiteKey: string | null;
}

const subscribeNone = () => () => {};

type Errors = { code?: string; password?: string; confirm?: string };

/**
 * Bước 2 quên mật khẩu (`POST /auth/password/reset`, US-015 §2.2; design-system-v2 §12.8): mã 6 số + mật khẩu mới + nhập lại.
 * - MỌI lỗi mã (sai, hết hạn, hết lượt, tài khoản không tồn tại — T27-5) hiện một thông điệp "hết hạn", khoá ô mã, "Gửi lại mã" nổi bật;
 *   mật khẩu đã nhập được giữ.
 * - "Gửi lại mã" gọi lại `POST /auth/password/forgot` nên cần Turnstile (chế độ ẩn) — quyết định PO 2026-10-07.
 * - Lỗi mật khẩu phổ biến: hiện nguyên `errors.password[0]`. Thành công -> `/dang-nhap` (không tự đăng nhập).
 */
export function ResetPasswordForm({ resendCooldownSeconds, captchaSiteKey }: ResetPasswordFormProps) {
  const router = useRouter();
  // sessionStorage chỉ đọc được ở trình duyệt: `null` ở server/lần hydrate đầu để tránh lệch HTML.
  const login = useSyncExternalStore(subscribeNone, readResetLogin, () => null);
  const hydrated = useSyncExternalStore(subscribeNone, () => true, () => false);

  const [code, setCode] = useState("");
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [errors, setErrors] = useState<Errors>({});
  const [codeDead, setCodeDead] = useState(false);
  const [throttle, setThrottle] = useState<string | null>(null);
  const [throttleMs, setThrottleMs] = useState<number | null>(null);
  const [banner, setBanner] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ tone: "info" | "danger"; text: string } | null>(null);
  const [pending, setPending] = useState(false);
  const [resending, setResending] = useState(false);
  const [done, setDone] = useState(false);
  // Thời gian chờ ban đầu: theo `resend_available_at` bước 1 để lại (nếu còn), nếu không thì theo cooldown cấu hình.
  // Lazy initializer chạy lại ở trình duyệt khi hydrate (HTML server chỉ là "Đang tải…", không lệch).
  const [wait, setWait] = useState<{ seconds: number; key: number }>(() => {
    const at = readResetResendAt();
    return { seconds: at ? secondsUntil(at) : resendCooldownSeconds, key: 0 };
  });
  const [focusSignal, setFocusSignal] = useState(0);
  const [captchaToken, setCaptchaToken] = useState<string | null>(null);
  const [captchaKey, setCaptchaKey] = useState(0);

  const captchaEnabled = typeof captchaSiteKey === "string" && captchaSiteKey.trim() !== "";
  const throttled = throttle !== null;
  const waitSeconds = wait.seconds;

  useEffect(() => {
    if (throttleMs === null) return;
    const id = setTimeout(() => {
      setThrottle(null);
      setThrottleMs(null);
    }, Math.min(throttleMs, 86_400_000));
    return () => clearTimeout(id);
  }, [throttleMs]);

  if (!hydrated) return <p className="text-base text-ink-soft">Đang tải…</p>;

  if (!login) {
    return (
      <div className="flex flex-col gap-5">
        <Alert tone="info" title="Bạn chưa yêu cầu mã đặt lại mật khẩu">
          Nhập email hoặc số điện thoại ở bước đầu để nhận mã.
        </Alert>
        <ButtonLink href={routes.forgotPassword} size="lg" block>
          Quên mật khẩu
        </ButtonLink>
        <AppLink href={routes.login} className="focus-ring inline-flex min-h-11 w-fit items-center rounded font-semibold text-primary hover:underline">
          Quay lại đăng nhập
        </AppLink>
      </div>
    );
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (codeDead || throttled || pending || !login) return;
    setBanner(null);
    const next: Errors = {};
    if (code.length !== OTP_LENGTH) next.code = `Vui lòng nhập đủ ${OTP_LENGTH} chữ số của mã.`;
    if (password.length < 8) next.password = "Mật khẩu mới cần tối thiểu 8 ký tự.";
    if (confirm !== password) next.confirm = "Xác nhận mật khẩu không khớp.";
    setErrors(next);
    if (Object.keys(next).length > 0) {
      focusFirstError([["reset-code", !!next.code], ["reset-password", !!next.password], ["reset-confirm", !!next.confirm]]);
      return;
    }

    setPending(true);
    try {
      await resetPassword({ login, code, password, password_confirmation: confirm });
      // Không xoá `login` ở đây (sẽ nháy màn "chưa yêu cầu mã" trong lúc chuyển trang): trang đăng nhập dọn khi có thông báo thành công.
      setDone(true);
      router.push(`${routes.login}?trang-thai=dat-lai-xong`);
    } catch (err) {
      const failure = classifyResetError(err);
      if (failure.kind === "code") {
        setCodeDead(true);
        setErrors({ code: RESET_CODE_MESSAGE });
        setFocusSignal((n) => n + 1);
        setCode("");
      } else if (failure.kind === "fields") {
        setErrors({ password: failure.errors.password, confirm: failure.errors.password_confirmation });
        focusFirstError([["reset-password", !!failure.errors.password], ["reset-confirm", !!failure.errors.password_confirmation]]);
      } else if (failure.kind === "throttled") {
        setThrottle(failure.message);
        setThrottleMs((failure.retryAfterSeconds ?? 60) * 1000);
      } else {
        setBanner(failure.message);
      }
      setPending(false);
    }
  }

  async function onResend() {
    if (!login) return;
    if (captchaEnabled && captchaToken === null) {
      setNotice({
        tone: "info",
        text: "Đang xác minh chống spam. Nếu thấy ô xác minh bên dưới, hãy tích vào đó rồi bấm “Gửi lại mã” lần nữa.",
      });
      return;
    }
    setResending(true);
    setNotice(null);
    try {
      const result = await forgotPassword({ login, captchaToken });
      const parsed = result.resendAvailableAt ? Date.parse(result.resendAvailableAt) : Number.NaN;
      setWait((w) => ({ seconds: Number.isNaN(parsed) ? resendCooldownSeconds : secondsUntil(result.resendAvailableAt), key: w.key + 1 }));
      setCodeDead(false);
      setCode("");
      setErrors((er) => ({ ...er, code: undefined }));
      setNotice({
        tone: "info",
        text: `${result.message ?? "Nếu thông tin tồn tại, chúng tôi đã gửi mã mới đến email của bạn."} Mã cũ không còn dùng được.`,
      });
      setFocusSignal((n) => n + 1);
    } catch (err) {
      const failure = classifyForgotError(err);
      setNotice({ tone: "danger", text: failure.kind === "captcha" ? CAPTCHA_FAILED_MESSAGE : failure.message });
    } finally {
      setResending(false);
      setCaptchaToken(null);
      setCaptchaKey((k) => k + 1);
    }
  }

  const locked = pending || done;

  return (
    <form noValidate onSubmit={onSubmit} className="flex flex-col gap-5" aria-busy={locked}>
      {throttle ? (
        <Alert tone="warning" title="Bạn đã thử quá nhiều lần">
          {throttle}
        </Alert>
      ) : null}
      {banner ? <Alert tone="danger">{banner}</Alert> : null}
      {notice ? (
        <Alert tone={notice.tone} role={notice.tone === "danger" ? "alert" : "status"}>
          {notice.text}
        </Alert>
      ) : null}

      <p className="flex flex-wrap items-baseline gap-x-2 text-sm text-ink-soft">
        <span>
          Tài khoản: <strong className="font-semibold text-ink">{login}</strong>
        </span>
        <AppLink href={routes.forgotPassword} className="focus-ring -my-3 inline-flex min-h-11 items-center rounded font-semibold text-primary hover:underline">
          Đổi
        </AppLink>
      </p>

      <Field
        id="reset-code"
        label="Mã xác nhận"
        required
        error={errors.code}
        hint={codeDead ? undefined : `Gồm ${OTP_LENGTH} chữ số, gửi tới email đã xác thực của tài khoản.`}
      >
        <OtpInput
          value={code}
          onChange={(v) => {
            setCode(v);
            if (errors.code && !codeDead) setErrors((er) => ({ ...er, code: undefined }));
          }}
          disabled={codeDead || throttled}
          busy={pending}
          focusSignal={focusSignal}
          length={OTP_LENGTH}
          autoFocus
        />
      </Field>

      {codeDead ? (
        <div className="flex flex-col gap-2">
          <ResendCode key={wait.key} waitSeconds={waitSeconds} onResend={() => void onResend()} loading={resending} emphasis block />
          <p className="text-sm text-ink-soft">Mật khẩu mới bạn đã nhập vẫn được giữ.</p>
        </div>
      ) : null}

      {/* Turnstile chế độ ẩn: chỉ hiện khi Cloudflare buộc người dùng tương tác; đặt ngay sau "Gửi lại mã" để người dùng thấy. */}
      {captchaEnabled && captchaSiteKey ? (
        <TurnstileWidget
          key={captchaKey}
          siteKey={captchaSiteKey}
          appearance="interaction-only"
          onToken={setCaptchaToken}
          onError={() => setNotice({ tone: "danger", text: CAPTCHA_FAILED_MESSAGE })}
        />
      ) : null}

      <Field
        id="reset-password"
        label="Mật khẩu mới"
        required
        error={errors.password}
        hint="Tối thiểu 8 ký tự. Tránh mật khẩu dễ đoán như 12345678."
      >
        <PasswordInput autoComplete="new-password" maxLength={128} value={password} disabled={locked} onChange={(e) => setPassword(e.target.value)} />
      </Field>
      <Field id="reset-confirm" label="Nhập lại mật khẩu mới" required error={errors.confirm}>
        <PasswordInput autoComplete="new-password" maxLength={128} value={confirm} disabled={locked} onChange={(e) => setConfirm(e.target.value)} />
      </Field>

      <p className="text-sm text-ink-soft">Sau khi đặt lại, mọi thiết bị đang đăng nhập tài khoản này sẽ bị đăng xuất.</p>

      <Button type="submit" size="lg" block loading={locked} loadingText="Đang đặt lại…" disabled={codeDead || throttled}>
        Đặt lại mật khẩu
      </Button>

      {!codeDead ? (
        <div className="flex flex-col gap-3 border-t border-line pt-5 sm:flex-row sm:items-start sm:justify-between">
          <p className="text-sm text-ink-soft">Chưa nhận được mã?</p>
          <ResendCode key={wait.key} waitSeconds={waitSeconds} onResend={() => void onResend()} loading={resending} />
        </div>
      ) : null}

      <AppLink href={routes.login} className="focus-ring inline-flex min-h-11 w-fit items-center rounded font-semibold text-primary hover:underline">
        Quay lại đăng nhập
      </AppLink>
    </form>
  );
}
