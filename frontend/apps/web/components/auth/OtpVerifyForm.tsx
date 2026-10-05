"use client";

import { useCallback, useEffect, useId, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { ApiError } from "@vitaminvui/api-client";
import { Alert, Button, OtpInput } from "@vitaminvui/ui";
import { sendOtp, verifyOtp } from "@/lib/auth/api";
import { useAuth } from "@/lib/auth/AuthProvider";
import { readOtpSentAt, setAccountFlash } from "@/lib/auth/flash";
import { formatCountdown, maskEmail, OTP_LENGTH, otpErrorMessage, secondsUntil } from "@/lib/auth/otp";
import { ChangeContactForm } from "./ChangeContactForm";

export interface OtpVerifyFormProps {
  /** `otp.ttl_minutes` từ `/config/public`. */
  ttlMinutes: number;
  /** `otp.resend_cooldown_seconds` từ `/config/public` — chỉ dùng khi response không có `resend_available_at`. */
  resendCooldownSeconds: number;
}

/** Màn `/xac-thuc-otp` (design US-001 §2.2): nhập mã 6 số, gửi lại có đếm ngược, đổi email/SĐT. */
export function OtpVerifyForm({ ttlMinutes, resendCooldownSeconds }: OtpVerifyFormProps) {
  const router = useRouter();
  const { state, refresh } = useAuth();
  const errorId = useId();

  const [code, setCode] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [resending, setResending] = useState(false);
  // Vừa đăng ký xong thì server đã gửi mã: cooldown chạy từ mốc đó (nếu còn).
  const [resendEndAt, setResendEndAt] = useState<number | null>(() => {
    const sentAt = readOtpSentAt();
    if (sentAt === null) return null;
    const end = sentAt + resendCooldownSeconds * 1000;
    return end > Date.now() ? end : null;
  });
  const [remaining, setRemaining] = useState(0);
  const [focusSignal, setFocusSignal] = useState(0);
  const [changing, setChanging] = useState(false);

  // Khách → đăng nhập rồi quay lại; đã xác thực → về trang chủ.
  useEffect(() => {
    if (state.status === "guest") router.replace("/dang-nhap?next=/xac-thuc-otp");
    else if (state.status === "user" && state.user.is_verified) router.replace("/");
  }, [state, router]);

  // Đếm ngược nút "Gửi lại mã" theo mốc tuyệt đối (không trôi khi tab bị throttle).
  useEffect(() => {
    if (resendEndAt === null) return;
    const tick = () => {
      const left = Math.max(0, Math.ceil((resendEndAt - Date.now()) / 1000));
      setRemaining(left);
      if (left === 0) setResendEndAt(null);
    };
    tick();
    const id = setInterval(tick, 1000);
    return () => clearInterval(id);
  }, [resendEndAt]);

  const startCooldownSeconds = useCallback((seconds: number) => {
    setResendEndAt(seconds > 0 ? Date.now() + seconds * 1000 : null);
  }, []);

  const startCooldown = useCallback(
    (resendAvailableAt: string | null) => {
      const parsed = resendAvailableAt ? Date.parse(resendAvailableAt) : NaN;
      const seconds = Number.isNaN(parsed) ? resendCooldownSeconds : secondsUntil(resendAvailableAt);
      startCooldownSeconds(seconds);
    },
    [resendCooldownSeconds, startCooldownSeconds],
  );

  async function submit(value: string) {
    if (pending) return;
    if (value.length !== OTP_LENGTH) {
      setError(`Vui lòng nhập đủ ${OTP_LENGTH} chữ số.`);
      return;
    }
    setPending(true);
    setError(null);
    setNotice(null);
    try {
      await verifyOtp(value);
      setAccountFlash("verified");
      router.replace("/");
      router.refresh();
    } catch (err) {
      const hint =
        err instanceof ApiError && err.code === "TOO_MANY_ATTEMPTS" ? ' Bấm "Gửi lại mã" để nhận mã mới.' : "";
      setError(otpErrorMessage(err) + hint);
      setCode("");
      setPending(false);
      setFocusSignal((n) => n + 1);
    }
  }

  function onSubmit(e: FormEvent) {
    e.preventDefault();
    void submit(code);
  }

  async function onResend() {
    setResending(true);
    setError(null);
    setNotice(null);
    try {
      const { resendAvailableAt } = await sendOtp();
      startCooldown(resendAvailableAt);
      setCode("");
      setNotice("Đã gửi mã mới. Mã cũ không còn hiệu lực.");
    } catch (err) {
      if (err instanceof ApiError && err.status === 429 && err.retryAfterSeconds) {
        startCooldownSeconds(err.retryAfterSeconds);
      }
      setError(otpErrorMessage(err));
    } finally {
      setResending(false);
    }
  }

  if (state.status === "error") {
    return (
      <div className="space-y-4">
        <Alert variant="danger">Không tải được thông tin tài khoản. Vui lòng kiểm tra kết nối và thử lại.</Alert>
        <Button type="button" onClick={() => void refresh()}>
          Thử lại
        </Button>
      </div>
    );
  }

  if (state.status !== "user" || state.user.is_verified) {
    return <p className="text-sm text-gray-700">Đang tải…</p>;
  }

  const email = state.user.email;
  const coolingDown = remaining > 0;

  if (changing) {
    return (
      <ChangeContactForm
        email={email ?? ""}
        phone={state.user.phone ?? ""}
        onCancel={() => setChanging(false)}
        onDone={async (resendAvailableAt) => {
          await refresh();
          setError(null);
          if (resendAvailableAt === null) {
            // Server không gửi mã mới (ví dụ chỉ đổi SĐT): giữ nguyên mã email đang chờ và cooldown hiện có.
            setNotice("Đã cập nhật thông tin liên hệ.");
          } else {
            startCooldown(resendAvailableAt);
            setCode("");
            setNotice("Đã cập nhật thông tin liên hệ và gửi mã xác thực mới.");
          }
          setChanging(false);
        }}
      />
    );
  }

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-5">
      <p className="text-sm text-gray-700">
        Chúng tôi đã gửi mã gồm {OTP_LENGTH} chữ số tới <strong>{email ? maskEmail(email) : "email của bạn"}</strong>.
        Mã có hiệu lực trong {ttlMinutes} phút.
      </p>

      {error ? (
        <Alert id={errorId} variant="danger">
          {error}
        </Alert>
      ) : null}
      {notice ? <Alert variant="success">{notice}</Alert> : null}

      <OtpInput
        value={code}
        onChange={(v) => {
          setCode(v);
          if (error) setError(null);
        }}
        onComplete={(v) => void submit(v)}
        busy={pending}
        focusSignal={focusSignal}
        invalid={error !== null}
        describedBy={error ? errorId : undefined}
        autoFocus
        length={OTP_LENGTH}
        label="Mã xác thực"
      />

      <Button type="submit" size="lg" loading={pending} className="w-full" disabled={code.length !== OTP_LENGTH}>
        Xác nhận
      </Button>

      <div className="flex flex-col items-center gap-2 text-sm">
        <Button
          type="button"
          variant="ghost"
          loading={resending}
          disabled={coolingDown || pending}
          onClick={onResend}
        >
          {coolingDown ? `Gửi lại mã sau ${formatCountdown(remaining)}` : "Gửi lại mã"}
        </Button>
        <button
          type="button"
          onClick={() => setChanging(true)}
          disabled={pending}
          className="inline-flex min-h-11 items-center px-2 font-medium text-indigo-700 hover:underline disabled:opacity-50"
        >
          Đổi email/SĐT
        </button>
      </div>
    </form>
  );
}
