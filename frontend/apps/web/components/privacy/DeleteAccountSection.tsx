"use client";

import { useState, type FormEvent } from "react";
import { ApiError } from "@vitaminvui/api-client";
import { Alert, Button, ButtonLink, Dialog, Field, OtpInput, ResendCode } from "@vitaminvui/ui/v2";
import { classifyOtpVerifyError } from "@/lib/auth/errors";
import { setAccountFlash } from "@/lib/auth/flash";
import { OTP_LENGTH, secondsUntil } from "@/lib/auth/otp";
import { routes } from "@/lib/routes";
import { confirmAccountDeletion, sendDeleteOtp } from "@/lib/privacy/api";
import { classifyDeleteSendError, pendingPaymentMessage } from "@/lib/privacy/errors";

/**
 * Khối "Xoá tài khoản" (api-contract §2.8.5): cảnh báo hậu quả -> gửi OTP email -> nhập mã -> xoá (ẩn danh hoá).
 * Xong: bỏ cache CSRF, đặt cờ thông báo một lần rồi tải cứng `/` (AuthProvider dựng lại, không còn state đăng nhập).
 */
export function DeleteAccountSection() {
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  return (
    <div className="flex flex-col gap-4">
      <Alert tone="warning" title="Xoá tài khoản không hoàn tác được">
        Thông tin cá nhân của bạn sẽ được ẩn danh. Bạn sẽ không đăng nhập lại được bằng tài khoản này.
      </Alert>
      <div>
        <Button variant="danger" onClick={() => setOpen(true)}>
          Xoá tài khoản của tôi
        </Button>
      </div>
      <Dialog open={open} onClose={() => setOpen(false)} dismissible={!busy} title="Xoá tài khoản">
        {open ? <DeleteFlow onBusy={setBusy} onClose={() => setOpen(false)} /> : null}
      </Dialog>
    </div>
  );
}

type Step = { name: "warn" } | { name: "otp"; destination: string; wait: number; key: number };

function DeleteFlow({ onBusy, onClose }: { onBusy: (b: boolean) => void; onClose: () => void }) {
  const [step, setStep] = useState<Step>({ name: "warn" });
  const [alert, setAlert] = useState<{ tone: "danger" | "warning" | "info"; body: string; verify?: boolean } | null>(null);
  const [pending, setPending] = useState(false);
  const [code, setCode] = useState("");
  const [fieldError, setFieldError] = useState<string | null>(null);
  const [mustResend, setMustResend] = useState(false);
  const [lockedReason, setLockedReason] = useState<string | undefined>();
  const [focusSignal, setFocusSignal] = useState(0);
  const [done, setDone] = useState(false);

  function startOtp(destination: string, wait: number) {
    setStep((s) => ({ name: "otp", destination, wait, key: s.name === "otp" ? s.key + 1 : 0 }));
    setCode("");
    setMustResend(false);
    setLockedReason(undefined);
    setFieldError(null);
    setFocusSignal((n) => n + 1);
  }

  async function send() {
    if (pending) return;
    setPending(true);
    onBusy(true);
    setAlert(null);
    try {
      const res = await sendDeleteOtp();
      startOtp(res.destination_masked, secondsUntil(res.resend_available_at));
    } catch (err) {
      const f = classifyDeleteSendError(err);
      if (f.kind === "not-verified") setAlert({ tone: "danger", body: f.message, verify: true });
      else if (f.kind === "pending-payment") setAlert({ tone: "warning", body: f.message });
      else if (f.kind === "wait") {
        // Cooldown 60s: nếu đang ở bước mã thì chỉ đếm ngược lại.
        setAlert({ tone: "danger", body: f.message });
        setStep((s) => (s.name === "otp" ? { ...s, wait: f.seconds, key: s.key + 1 } : s));
      } else if (f.kind === "locked") {
        setLockedReason(f.message);
        setAlert({ tone: "danger", body: f.message });
      } else if (f.kind === "delivery") setAlert({ tone: "danger", body: "Chưa gửi được mã do hệ thống thư gặp sự cố. Bạn có thể thử lại ngay." });
      else setAlert({ tone: "danger", body: f.message });
    } finally {
      setPending(false);
      onBusy(false);
    }
  }

  async function confirm(e: FormEvent) {
    e.preventDefault();
    if (pending || done || mustResend) return;
    if (code.length !== OTP_LENGTH) {
      setFieldError(`Vui lòng nhập đủ ${OTP_LENGTH} chữ số.`);
      setFocusSignal((n) => n + 1);
      return;
    }
    setPending(true);
    onBusy(true);
    setAlert(null);
    setFieldError(null);
    try {
      await confirmAccountDeletion(code);
      setDone(true);
      setAccountFlash("account-deleted");
      // Tải cứng có chủ đích: dựng lại AuthProvider/CSRF/state, không để lại dữ liệu của tài khoản đã xoá.
      // eslint-disable-next-line @next/next/no-location-assign-relative-destination
      window.location.assign("/");
    } catch (err) {
      setPending(false);
      onBusy(false);
      setCode("");
      setFocusSignal((n) => n + 1);
      if (err instanceof ApiError && err.code === "ACCOUNT_HAS_PENDING_PAYMENT") {
        setStep({ name: "warn" });
        setAlert({ tone: "warning", body: pendingPaymentMessage(err) });
        return;
      }
      if (err instanceof ApiError && err.code === "ACCOUNT_NOT_VERIFIED") {
        setStep({ name: "warn" });
        setAlert({ tone: "danger", body: err.message, verify: true });
        return;
      }
      const f = classifyOtpVerifyError(err);
      if (f.kind === "invalid") setFieldError(f.message);
      else if (f.kind === "must-resend") {
        setFieldError(f.message);
        setMustResend(true);
      } else setAlert({ tone: "danger", body: f.message });
    }
  }

  const alertNode = alert ? (
    <Alert
      tone={alert.tone}
      action={alert.verify ? <ButtonLink href={routes.verifyOtp} size="md">Xác thực email</ButtonLink> : undefined}
    >
      {alert.body}
      {alert.verify ? " Hãy xác thực email của bạn trước, rồi quay lại đây." : null}
    </Alert>
  ) : null;

  if (step.name === "warn") {
    return (
      <div className="flex flex-col gap-4">
        <div className="text-base text-ink">
          <p className="font-semibold">Khi xoá tài khoản:</p>
          <ul className="mt-2 list-disc pl-5 text-ink-soft">
            <li>Tên, email, số điện thoại, ngày sinh và liên hệ phụ huynh được xoá hoặc ẩn danh.</li>
            <li>Bạn không đăng nhập lại được; mọi thiết bị đang đăng nhập bị đăng xuất.</li>
            <li>Khóa học đang học và đơn hàng được giữ lại (không còn gắn với thông tin cá nhân của bạn) nhưng bạn không truy cập được nữa.</li>
            <li>Bạn có thể dùng lại email và số điện thoại cũ để đăng ký tài khoản mới.</li>
          </ul>
          <p className="mt-3 text-ink-soft">Chúng tôi sẽ gửi mã xác nhận tới email đã xác thực của bạn.</p>
        </div>
        {alertNode}
        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="secondary" onClick={onClose} disabled={pending}>
            Giữ tài khoản
          </Button>
          <Button variant="danger" onClick={() => void send()} loading={pending} loadingText="Đang gửi mã…">
            Gửi mã xác nhận
          </Button>
        </div>
      </div>
    );
  }

  return (
    <form onSubmit={confirm} noValidate aria-busy={pending} className="flex flex-col gap-4">
      <p className="text-base text-ink-soft">
        Mã gồm {OTP_LENGTH} chữ số đã gửi tới <strong className="font-semibold text-ink">{step.destination}</strong>. Nhập mã để xoá vĩnh viễn tài khoản.
      </p>
      {alertNode}
      <Field label="Mã xác nhận" required error={fieldError ?? undefined}>
        <OtpInput value={code} onChange={setCode} busy={pending} disabled={mustResend || done} focusSignal={focusSignal} length={OTP_LENGTH} autoFocus />
      </Field>
      <ResendCode key={step.key} waitSeconds={step.wait} onResend={() => void send()} loading={pending && !done} emphasis={mustResend} lockedReason={lockedReason} />
      <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <Button type="button" variant="secondary" onClick={onClose} disabled={pending}>
          Huỷ
        </Button>
        <Button type="submit" variant="danger" loading={pending} loadingText={done ? "Đang chuyển…" : "Đang xoá…"} disabled={mustResend || done}>
          Xoá tài khoản vĩnh viễn
        </Button>
      </div>
    </form>
  );
}
