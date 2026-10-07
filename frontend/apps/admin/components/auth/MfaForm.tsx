"use client";

import { useEffect, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { ApiError } from "@vitaminvui/api-client";
import { Alert, Button, Field, LoadingRegion, OtpInput, Spinner } from "@vitaminvui/ui/v2";
import { logoutStaff, resendMfa, verifyMfa } from "@/lib/auth/api";
import { mfaErrorMessage } from "@/lib/auth/errors";
import { clearMfaHint, readMfaHint, readMfaResendAt, saveMfaResendAt } from "@/lib/auth/mfaHint";
import { useSession } from "@/lib/auth/SessionProvider";
import { formatCountdown, secondsUntil } from "@/lib/auth/countdown";
import { safeNext } from "@/lib/nav";

export const MFA_CODE_LENGTH = 6;
/** Mã OTP hiệu lực 10 phút (api-contract "Bổ sung từ T04"; T28 chưa ghi riêng cho `staff_login_mfa`). */
export const MFA_TTL_MINUTES = 10;

/** Màn MFA (US-016 §2.2): nhập mã 6 số gửi qua email. Chỉ Admin/Quản lý trang. */
export function MfaForm({ next }: { next?: string | null }) {
  const router = useRouter();
  const { state, refresh } = useSession();
  const target = safeNext(next);

  const [code, setCode] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [focusSignal, setFocusSignal] = useState(0);
  const [hint] = useState<string | null>(() => (typeof window === "undefined" ? null : readMfaHint()));

  const [resendEndAt, setResendEndAt] = useState<number | null>(() => {
    if (typeof window === "undefined") return null;
    const s = secondsUntil(readMfaResendAt());
    return s > 0 ? Date.now() + s * 1000 : null;
  });
  const [remaining, setRemaining] = useState(0);
  const [resending, setResending] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  // Đếm ngược theo mốc tuyệt đối (không trôi khi tab bị throttle).
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

  async function onResend() {
    setResending(true);
    setError(null);
    setNotice(null);
    try {
      const { resendAvailableAt } = await resendMfa();
      saveMfaResendAt(resendAvailableAt);
      const s = secondsUntil(resendAvailableAt);
      setResendEndAt(Date.now() + (s > 0 ? s : 60) * 1000);
      setCode("");
      setNotice("Đã gửi mã mới. Mã cũ không còn hiệu lực.");
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        // Đã qua MFA (ví dụ ở tab khác): đồng bộ phiên, effect phía trên sẽ chuyển trang.
        await refresh();
        return;
      }
      if (err instanceof ApiError && err.status === 429 && err.retryAfterSeconds) {
        setResendEndAt(Date.now() + err.retryAfterSeconds * 1000);
      }
      setError(mfaErrorMessage(err));
    } finally {
      setResending(false);
    }
  }

  // Chỉ đúng khi phiên đang "chờ MFA"; các trạng thái khác dẫn về chỗ phù hợp.
  useEffect(() => {
    if (state.kind === "guest") router.replace("/dang-nhap");
    else if (state.kind === "staff") router.replace(target);
    else if (state.kind === "password_change_required") router.replace(`/doi-mat-khau?next=${encodeURIComponent(target)}`);
  }, [state, router, target]);

  async function submit(value: string) {
    if (pending) return;
    if (value.length !== MFA_CODE_LENGTH) {
      setError(`Vui lòng nhập đủ ${MFA_CODE_LENGTH} chữ số.`);
      return;
    }
    setPending(true);
    setError(null);
    try {
      await verifyMfa(value);
      clearMfaHint();
      const after = await refresh();
      if (after.kind === "password_change_required") {
        router.replace(`/doi-mat-khau?next=${encodeURIComponent(target)}`);
      } else {
        router.replace(target);
      }
      router.refresh();
    } catch (err) {
      setError(mfaErrorMessage(err));
      setCode("");
      setPending(false);
      setFocusSignal((n) => n + 1);
    }
  }

  async function restart() {
    setPending(true);
    try {
      await logoutStaff();
    } catch {
      /* lỗi mạng: vẫn cho quay lại đăng nhập, form sẽ báo nếu phiên cũ còn */
    }
    clearMfaHint();
    router.replace("/dang-nhap");
  }

  if (state.kind === "error") {
    return (
      <div className="flex flex-col gap-4">
        <Alert tone="danger">Không kiểm tra được phiên đăng nhập. Vui lòng kiểm tra kết nối và thử lại.</Alert>
        <Button type="button" onClick={() => void refresh()}>
          Thử lại
        </Button>
      </div>
    );
  }
  if (state.kind === "locked") {
    return <Alert tone="danger">Tài khoản của bạn đã bị khóa. Vui lòng liên hệ Admin.</Alert>;
  }
  if (state.kind !== "mfa_required") {
    return (
      <LoadingRegion className="flex items-center gap-2 text-sm text-ink-soft">
        <Spinner label={null} className="size-4" />
        Đang tải…
      </LoadingRegion>
    );
  }

  function onSubmit(e: FormEvent) {
    e.preventDefault();
    void submit(code);
  }

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-5">
      <p className="text-base leading-relaxed text-ink-soft">
        Vì tài khoản của bạn có quyền truy cập dữ liệu học sinh, chúng tôi đã gửi mã xác nhận gồm {MFA_CODE_LENGTH} chữ số tới{" "}
        <strong className="font-semibold text-ink">{hint ?? "email của bạn"}</strong>. Mã có hiệu lực trong {MFA_TTL_MINUTES} phút.
      </p>

      {notice ? <Alert tone="success">{notice}</Alert> : null}

      <Field label="Mã xác nhận" required error={error ?? undefined}>
        <OtpInput
          value={code}
          onChange={(v) => {
            setCode(v);
            if (error) setError(null);
          }}
          onComplete={(v) => void submit(v)}
          busy={pending}
          focusSignal={focusSignal}
          autoFocus
          length={MFA_CODE_LENGTH}
        />
      </Field>

      <Button type="submit" size="lg" block loading={pending} loadingText="Đang xác nhận…" disabled={code.length !== MFA_CODE_LENGTH}>
        Xác nhận
      </Button>

      <div className="flex flex-col items-center gap-1">
        <Button type="button" variant="ghost" loading={resending} disabled={remaining > 0 || pending} onClick={() => void onResend()}>
          {remaining > 0 ? `Gửi lại mã sau ${formatCountdown(remaining)}` : "Gửi lại mã"}
        </Button>
        <Button type="button" variant="ghost" onClick={() => void restart()} disabled={pending}>
          Đăng nhập bằng tài khoản khác
        </Button>
      </div>
    </form>
  );
}
