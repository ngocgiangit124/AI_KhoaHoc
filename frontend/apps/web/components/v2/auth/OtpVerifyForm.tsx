"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";
import { Alert, Button, ButtonLink, Field, OtpInput, ResendCode, useToast } from "@vitaminvui/ui/v2";
import { routes } from "@/lib/v2/routes";

/**
 * Trạng thái mẫu của màn xác thực OTP (POST /auth/otp/verify, POST /auth/otp/send):
 * - `sai`        422 `OTP_INVALID` → lỗi dưới ô, giữ nguyên nút "Xác nhận".
 * - `het-han`    422 `OTP_EXPIRED` → lỗi dưới ô + "Gửi lại mã" thành nút chính (việc duy nhất làm được).
 * - `het-luot`   429 `TOO_MANY_ATTEMPTS` của MÃ (sai 5 lần) → như hết hạn: phải gửi mã mới.
 * - `qua-nhieu`  429 throttle (`Retry-After`; 20 lần/ngày → khoá xác thực 24h) → Alert, khoá ô nhập.
 * - `gui-loi`    503 `OTP_DELIVERY_FAILED` khi gửi lại → Alert danger, gửi lại được ngay.
 * - `het-luot-gui` 429 khi gửi (trần 5/giờ, 10/ngày) → khoá nút gửi lại kèm câu giải thích.
 */
export type OtpDemoState = "sai" | "het-han" | "het-luot" | "qua-nhieu" | "gui-loi" | "het-luot-gui";

const OTP_INVALID = "Mã OTP không đúng, vui lòng thử lại.";
const OTP_EXPIRED = "Mã OTP đã hết hạn. Bấm “Gửi lại mã” để nhận mã mới.";
const CODE_LOCKED = "Bạn đã nhập sai mã này 5 lần. Bấm “Gửi lại mã” để nhận mã mới.";

export interface OtpVerifyFormProps {
  /** Email đã che (từ /auth/me). */
  maskedEmail: string;
  /** `otp.ttl_minutes` từ /config/public. */
  ttlMinutes: number;
  /** Giây còn phải chờ để gửi lại (từ `resend_available_at`). */
  resendWait: number;
  demo?: OtpDemoState;
}

/**
 * Màn `/xac-thuc-otp` v2 (US-001 §2.2, AC8). Nhập đủ 6 số là tự gửi — vẫn có nút "Xác nhận" cho
 * người dùng bàn phím/trình đọc màn hình. Lỗi luôn nằm ngay dưới ô mã.
 * TODO(dev): nối POST /auth/otp/verify + /auth/otp/send (như components/auth/OtpVerifyForm.tsx v1),
 * thành công → router.replace("/") + toast "Xác thực tài khoản thành công".
 */
export function OtpVerifyForm({ maskedEmail, ttlMinutes, resendWait, demo }: OtpVerifyFormProps) {
  const toast = useToast();
  const [code, setCode] = useState("");
  const [state, setState] = useState<OtpDemoState | undefined>(demo);
  const [loading, setLoading] = useState(false);
  const [resending, setResending] = useState(false);
  const [wait, setWait] = useState({ seconds: demo === "het-han" || demo === "het-luot" || demo === "gui-loi" ? 0 : resendWait, key: 0 });
  const [notice, setNotice] = useState<string>();
  const [focusSignal, setFocusSignal] = useState(0);
  const [done, setDone] = useState(false);

  const mustResend = state === "het-han" || state === "het-luot";
  const throttled = state === "qua-nhieu";
  const fieldError = state === "sai" ? OTP_INVALID : state === "het-han" ? OTP_EXPIRED : state === "het-luot" ? CODE_LOCKED : undefined;

  function verify(value: string) {
    if (loading || mustResend || throttled) return;
    if (value.length !== 6) {
      setState("sai");
      setFocusSignal((n) => n + 1);
      return;
    }
    setLoading(true);
    setNotice(undefined);
    setTimeout(() => {
      setLoading(false);
      // Bản xem trước: "000000" giả lập mã sai, mã khác coi như đúng.
      if (value === "000000") {
        setState("sai");
        setCode("");
        setFocusSignal((n) => n + 1);
        return;
      }
      setDone(true);
      toast.show({ tone: "success", title: "Xác thực tài khoản thành công" });
    }, 800);
  }

  function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    verify(code);
  }

  function resend() {
    setResending(true);
    setTimeout(() => {
      setResending(false);
      setState(undefined);
      setCode("");
      setWait((w) => ({ seconds: 60, key: w.key + 1 }));
      setNotice(`Đã gửi mã mới tới ${maskedEmail}. Mã cũ không còn dùng được.`);
      setFocusSignal((n) => n + 1);
    }, 800);
  }

  if (done) {
    return (
      <div className="flex flex-col gap-5">
        <Alert tone="success" title="Tài khoản đã được xác thực">
          Bạn đã có thể đăng ký khóa học miễn phí. Bản thật sẽ tự chuyển về trang chủ.
        </Alert>
        <ButtonLink href={routes.home} size="lg" block>
          Về trang chủ
        </ButtonLink>
      </div>
    );
  }

  return (
    <form noValidate onSubmit={onSubmit} className="flex flex-col gap-5">
      {throttled ? (
        <Alert tone="warning" title="Bạn đã thử quá nhiều lần">
          Vui lòng thử lại sau 12 phút. Thông điệp và thời gian lấy từ phản hồi của máy chủ.
        </Alert>
      ) : null}
      {state === "gui-loi" ? (
        <Alert tone="danger" title="Chưa gửi được mã">
          Hệ thống gửi thư đang gặp sự cố. Bạn có thể bấm “Gửi lại mã” ngay.
        </Alert>
      ) : null}
      {notice ? <Alert tone="info">{notice}</Alert> : null}

      <Field
        label="Mã xác nhận"
        required
        error={fieldError}
        hint={mustResend ? undefined : `Gồm 6 chữ số, có hiệu lực ${ttlMinutes} phút. Không thấy thư? Xem thêm mục Thư rác hoặc Quảng cáo.`}
      >
        <OtpInput
          value={code}
          onChange={(v) => {
            setCode(v);
            if (state === "sai") setState(undefined);
          }}
          onComplete={verify}
          busy={loading}
          disabled={throttled || mustResend}
          autoFocus={!demo}
          focusSignal={focusSignal}
        />
      </Field>

      {mustResend ? (
        <ResendCode key={wait.key} waitSeconds={wait.seconds} onResend={resend} loading={resending} emphasis block />
      ) : (
        <>
          <Button type="submit" size="lg" block loading={loading} loadingText="Đang kiểm tra…" disabled={throttled}>
            Xác nhận
          </Button>
          <div className="flex flex-col gap-3 border-t border-line pt-5 sm:flex-row sm:items-start sm:justify-between">
            <p className="text-sm text-ink-soft">Chưa nhận được mã?</p>
            <ResendCode
              key={wait.key}
              waitSeconds={wait.seconds}
              onResend={resend}
              loading={resending}
              lockedReason={state === "het-luot-gui" ? "Bạn đã gửi lại mã nhiều lần trong hôm nay. Vui lòng thử lại vào ngày mai." : undefined}
            />
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
