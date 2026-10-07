"use client";

import { useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Alert, Button, Field, OtpInput, ResendCode } from "@vitaminvui/ui/v2";
import { sendOtp, verifyOtp } from "@/lib/auth/api";
import { useAuth } from "@/lib/auth/AuthProvider";
import { classifyOtpSendError, classifyOtpVerifyError } from "@/lib/auth/errors";
import { readOtpSentAt, setAccountFlash } from "@/lib/auth/flash";
import { maskEmail, OTP_LENGTH, secondsUntil } from "@/lib/auth/otp";
import { routes } from "@/lib/routes";

export interface OtpVerifyFormProps {
  /** `otp.ttl_minutes` từ `/config/public`. */
  ttlMinutes: number;
  /** `otp.resend_cooldown_seconds` từ `/config/public` — chỉ dùng khi response không có `resend_available_at`. */
  resendCooldownSeconds: number;
}

interface SendAlert {
  tone: "info" | "danger";
  title?: string;
  body: string;
}

/**
 * Màn `/xac-thuc-otp` (US-001 §2.2, AC8; design-system-v2 §12.8). Nhập đủ 6 số là tự gửi, vẫn có nút "Xác nhận".
 * Lỗi luôn nằm dưới ô mã: `OTP_INVALID` xoá mã + nhập lại; `OTP_EXPIRED`/429 của mã khoá ô, "Gửi lại mã" thành nút chính;
 * 429 throttle khoá ô + nút; 503 `OTP_DELIVERY_FAILED` gửi lại được ngay. Đổi email/SĐT ở `/tai-khoan#doi-lien-he`.
 */
export function OtpVerifyForm({ ttlMinutes, resendCooldownSeconds }: OtpVerifyFormProps) {
  const router = useRouter();
  const { state, refresh } = useAuth();

  const [code, setCode] = useState("");
  const [pending, setPending] = useState(false);
  const [fieldError, setFieldError] = useState<string | null>(null);
  const [mustResend, setMustResend] = useState(false);
  const [throttle, setThrottle] = useState<{ message: string } | null>(null);
  const [verifyBanner, setVerifyBanner] = useState<string | null>(null);
  const [sendAlert, setSendAlert] = useState<SendAlert | null>(null);
  const [lockedReason, setLockedReason] = useState<string | undefined>();
  const [resending, setResending] = useState(false);
  // Vừa đăng ký xong thì server đã gửi mã: cooldown chạy từ mốc đó (nếu còn). `key` đổi để ResendCode chạy lại đồng hồ.
  const [wait, setWait] = useState<{ seconds: number; key: number }>(() => {
    const sentAt = readOtpSentAt();
    if (sentAt === null) return { seconds: 0, key: 0 };
    const left = Math.ceil((sentAt + resendCooldownSeconds * 1000 - Date.now()) / 1000);
    return { seconds: Math.max(0, left), key: 0 };
  });
  const [focusSignal, setFocusSignal] = useState(0);

  // Khách → đăng nhập rồi quay lại; đã xác thực → về trang chủ.
  useEffect(() => {
    if (state.status === "guest") router.replace(`${routes.login}?next=${routes.verifyOtp}`);
    else if (state.status === "user" && state.user.is_verified) router.replace("/");
  }, [state, router]);

  // Hết thời gian chờ của throttle thì mở khoá ô nhập (mốc từ `Retry-After`).
  const [throttleMs, setThrottleMs] = useState<number | null>(null);
  useEffect(() => {
    if (throttleMs === null) return;
    const id = setTimeout(() => {
      setThrottle(null);
      setThrottleMs(null);
    }, Math.min(throttleMs, 86_400_000));
    return () => clearTimeout(id);
  }, [throttleMs]);

  function startWait(seconds: number) {
    setWait((w) => ({ seconds, key: w.key + 1 }));
  }

  async function submit(value: string) {
    if (pending || mustResend || throttle) return;
    if (value.length !== OTP_LENGTH) {
      setFieldError(`Vui lòng nhập đủ ${OTP_LENGTH} chữ số.`);
      setFocusSignal((n) => n + 1);
      return;
    }
    setPending(true);
    setFieldError(null);
    setVerifyBanner(null);
    setSendAlert(null);
    try {
      await verifyOtp(value);
      setAccountFlash("verified");
      router.replace("/");
      router.refresh();
    } catch (err) {
      const failure = classifyOtpVerifyError(err);
      switch (failure.kind) {
        case "invalid":
          setFieldError(failure.message);
          break;
        case "must-resend":
          setFieldError(failure.message);
          setMustResend(true);
          break;
        case "throttled":
          setThrottle({ message: failure.message });
          setThrottleMs((failure.retryAfterSeconds ?? 60) * 1000);
          break;
        case "other":
          setVerifyBanner(failure.message);
          break;
      }
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
    setFieldError(null);
    setVerifyBanner(null);
    setSendAlert(null);
    try {
      const { resendAvailableAt } = await sendOtp();
      const parsed = resendAvailableAt ? Date.parse(resendAvailableAt) : Number.NaN;
      startWait(Number.isNaN(parsed) ? resendCooldownSeconds : secondsUntil(resendAvailableAt));
      setCode("");
      setMustResend(false);
      setLockedReason(undefined);
      setSendAlert({
        tone: "info",
        body: `Đã gửi mã mới tới ${state.status === "user" && state.user.email ? maskEmail(state.user.email) : "email của bạn"}. Mã cũ không còn dùng được.`,
      });
      setFocusSignal((n) => n + 1);
    } catch (err) {
      const failure = classifyOtpSendError(err);
      if (failure.kind === "wait") {
        startWait(failure.seconds);
        setSendAlert({ tone: "danger", body: failure.message });
      } else if (failure.kind === "locked") {
        setLockedReason(failure.message);
      } else if (failure.kind === "delivery") {
        setSendAlert({ tone: "danger", title: "Chưa gửi được mã", body: "Hệ thống gửi thư đang gặp sự cố. Bạn có thể bấm “Gửi lại mã” ngay." });
      } else {
        setSendAlert({ tone: "danger", body: failure.message });
      }
    } finally {
      setResending(false);
    }
  }

  if (state.status === "error") {
    return (
      <div className="flex flex-col gap-4">
        <Alert tone="danger">Không tải được thông tin tài khoản. Vui lòng kiểm tra kết nối và thử lại.</Alert>
        <div>
          <Button type="button" onClick={() => void refresh()}>
            Thử lại
          </Button>
        </div>
      </div>
    );
  }

  if (state.status !== "user" || state.user.is_verified) {
    return <p className="text-base text-ink-soft">Đang tải…</p>;
  }

  const email = state.user.email;
  const throttled = throttle !== null;

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-5">
      <p className="text-base text-ink-soft">
        Chúng tôi đã gửi mã gồm {OTP_LENGTH} chữ số tới <strong className="font-semibold text-ink">{email ? maskEmail(email) : "email của bạn"}</strong>.
        Xác thực xong bạn có thể đăng ký khóa học.
      </p>

      {throttle ? (
        <Alert tone="warning" title="Bạn đã thử quá nhiều lần">
          {throttle.message}
        </Alert>
      ) : null}
      {verifyBanner ? <Alert tone="danger">{verifyBanner}</Alert> : null}
      {sendAlert ? (
        <Alert tone={sendAlert.tone} title={sendAlert.title}>
          {sendAlert.body}
        </Alert>
      ) : null}

      <Field
        label="Mã xác nhận"
        required
        error={fieldError ?? undefined}
        hint={mustResend ? undefined : `Gồm ${OTP_LENGTH} chữ số, có hiệu lực ${ttlMinutes} phút. Không thấy thư? Xem thêm mục Thư rác hoặc Quảng cáo.`}
      >
        <OtpInput
          value={code}
          onChange={(v) => {
            setCode(v);
            if (fieldError && !mustResend) setFieldError(null);
          }}
          onComplete={(v) => void submit(v)}
          busy={pending}
          disabled={throttled || mustResend}
          focusSignal={focusSignal}
          length={OTP_LENGTH}
          autoFocus
        />
      </Field>

      {mustResend ? (
        <ResendCode key={wait.key} waitSeconds={wait.seconds} onResend={() => void onResend()} loading={resending} lockedReason={lockedReason} emphasis block />
      ) : (
        <>
          <Button type="submit" size="lg" block loading={pending} loadingText="Đang kiểm tra…" disabled={throttled}>
            Xác nhận
          </Button>
          <div className="flex flex-col gap-3 border-t border-line pt-5 sm:flex-row sm:items-start sm:justify-between">
            <p className="text-sm text-ink-soft">Chưa nhận được mã?</p>
            <ResendCode key={wait.key} waitSeconds={wait.seconds} onResend={() => void onResend()} loading={resending} lockedReason={lockedReason} />
          </div>
        </>
      )}

      <p className="text-base text-ink-soft">
        Sai email?{" "}
        <Link href={`${routes.account}#doi-lien-he`} className="focus-ring rounded font-semibold text-primary hover:underline">
          Đổi email
        </Link>
        {" · "}
        <Link href={routes.home} className="focus-ring rounded font-semibold text-primary hover:underline">
          Để sau
        </Link>
      </p>
    </form>
  );
}
