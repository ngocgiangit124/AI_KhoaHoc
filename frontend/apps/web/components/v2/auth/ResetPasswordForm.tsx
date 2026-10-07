"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";
import { Alert, Button, Field, OtpInput, PasswordInput, ResendCode } from "@vitaminvui/ui/v2";
import { routes } from "@/lib/v2/routes";

/**
 * Trạng thái mẫu của POST /auth/password/reset:
 * - `het-han`   MỌI lỗi mã (sai, hết hạn, hết lượt, tài khoản không tồn tại...) → 422 `OTP_EXPIRED`
 *               cùng một thông điệp (không lộ tài khoản — T27-5). Hiện như hết hạn + "Gửi lại mã" nổi bật.
 * - `pho-bien`  422 field `password` (mật khẩu phổ biến) → hiện nguyên `errors.password[0]` dưới ô.
 * - `khong-khop` 422 field `password_confirmation`.
 * - `qua-nhieu` 429 throttle `otp-verify` (`Retry-After`) → Alert, khoá nút.
 */
export type ResetDemoState = "het-han" | "pho-bien" | "khong-khop" | "qua-nhieu";

const CODE_ERROR = "Mã OTP đã hết hạn hoặc không còn hiệu lực. Bấm “Gửi lại mã” để nhận mã mới.";
const COMMON_PASSWORD = "Mật khẩu quá phổ biến, dễ bị đoán. Vui lòng chọn mật khẩu khác.";

/**
 * Bước 2 đặt lại mật khẩu (US-015 §2.2): mã 6 số + mật khẩu mới + nhập lại.
 * - Lỗi mã KHÔNG xoá mật khẩu đã nhập (người dùng chỉ cần gửi lại mã rồi nhập mã mới).
 * - "Gửi lại mã" = gọi lại POST /auth/password/forgot nên cần captcha Turnstile (chế độ ẩn/managed).
 * - Thành công → /dang-nhap kèm thông báo (không tự đăng nhập).
 * TODO(dev): `login` lấy từ bước 1 (giữ trong sessionStorage, không đặt lên URL); captcha khi gửi lại.
 */
export function ResetPasswordForm({ login, demo }: { login: string; demo?: ResetDemoState }) {
  const router = useRouter();
  const [state, setState] = useState<ResetDemoState | undefined>(demo);
  const [code, setCode] = useState("");
  const [loading, setLoading] = useState(false);
  const [resending, setResending] = useState(false);
  const [wait, setWait] = useState({ seconds: demo === "het-han" ? 0 : 52, key: 0 });
  const [notice, setNotice] = useState<string>();
  const [errors, setErrors] = useState<{ code?: string; password?: string; confirm?: string }>(() => ({
    code: demo === "het-han" ? CODE_ERROR : undefined,
    password: demo === "pho-bien" ? COMMON_PASSWORD : undefined,
    confirm: demo === "khong-khop" ? "Xác nhận mật khẩu không khớp." : undefined,
  }));
  const [focusSignal, setFocusSignal] = useState(0);

  const codeDead = state === "het-han";
  const throttled = state === "qua-nhieu";

  function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (codeDead || throttled) return;
    const f = new FormData(e.currentTarget);
    const pw = String(f.get("password") ?? "");
    const cf = String(f.get("password_confirmation") ?? "");
    const next: typeof errors = {};
    if (code.length !== 6) next.code = "Vui lòng nhập đủ 6 chữ số của mã.";
    if (pw.length < 8) next.password = "Mật khẩu mới cần tối thiểu 8 ký tự.";
    if (cf !== pw) next.confirm = "Xác nhận mật khẩu không khớp.";
    setErrors(next);
    if (Object.keys(next).length) {
      const first = next.code ? "code" : next.password ? "password" : "password_confirmation";
      (e.currentTarget.elements.namedItem(first) as HTMLInputElement | null)?.focus();
      return;
    }
    setLoading(true);
    setTimeout(() => {
      setLoading(false);
      // Bản xem trước: mã 000000 giả lập OTP_EXPIRED; "matkhau123" giả lập mật khẩu phổ biến.
      if (code === "000000") {
        setState("het-han");
        setErrors({ code: CODE_ERROR });
        return;
      }
      if (pw === "matkhau123" || pw === "12345678") {
        setErrors({ password: COMMON_PASSWORD });
        (document.getElementsByName("password")[0] as HTMLInputElement | undefined)?.focus();
        return;
      }
      router.push(`${routes.login}?trang-thai=dat-lai-xong`);
    }, 900);
  }

  function resend() {
    setResending(true);
    setTimeout(() => {
      setResending(false);
      setState(undefined);
      setCode("");
      setErrors({});
      setWait((w) => ({ seconds: 60, key: w.key + 1 }));
      setNotice("Nếu thông tin tồn tại, chúng tôi đã gửi mã mới đến email của bạn. Mã cũ không còn dùng được.");
      setFocusSignal((n) => n + 1);
    }, 900);
  }

  return (
    <form noValidate onSubmit={onSubmit} className="flex flex-col gap-5">
      {throttled ? (
        <Alert tone="warning" title="Bạn đã thử quá nhiều lần">
          Vui lòng thử lại sau 8 phút.
        </Alert>
      ) : null}
      {notice ? <Alert tone="info">{notice}</Alert> : null}

      <p className="flex flex-wrap items-baseline gap-x-2 text-sm text-ink-soft">
        <span>
          Tài khoản: <strong className="font-semibold text-ink">{login}</strong>
        </span>
        <Link href={routes.forgot} className="focus-ring rounded font-semibold text-primary hover:underline">
          Đổi
        </Link>
      </p>

      <Field label="Mã xác nhận" required error={errors.code} hint={codeDead ? undefined : "Gồm 6 chữ số, gửi tới email đã xác thực của tài khoản. Có hiệu lực 10 phút."}>
        <OtpInput
          value={code}
          onChange={(v) => {
            setCode(v);
            if (errors.code && !codeDead) setErrors((er) => ({ ...er, code: undefined }));
          }}
          disabled={codeDead || throttled}
          busy={loading}
          autoFocus={!demo}
          focusSignal={focusSignal}
        />
      </Field>

      {codeDead ? (
        <div className="flex flex-col gap-2">
          <p className="rounded-control border border-dashed border-line-strong px-3 py-2 text-sm text-ink-soft">Captcha Turnstile (chế độ ẩn) chạy khi bấm gửi lại.</p>
          <ResendCode key={wait.key} waitSeconds={wait.seconds} onResend={resend} loading={resending} emphasis block />
          <p className="text-sm text-ink-soft">Mật khẩu mới bạn đã nhập vẫn được giữ.</p>
        </div>
      ) : null}

      <Field label="Mật khẩu mới" required error={errors.password} hint="Tối thiểu 8 ký tự. Tránh mật khẩu dễ đoán như 12345678.">
        <PasswordInput name="password" autoComplete="new-password" maxLength={128} />
      </Field>
      <Field label="Nhập lại mật khẩu mới" required error={errors.confirm}>
        <PasswordInput name="password_confirmation" autoComplete="new-password" maxLength={128} />
      </Field>

      <p className="text-sm text-ink-soft">Sau khi đặt lại, mọi thiết bị đang đăng nhập tài khoản này sẽ bị đăng xuất.</p>

      <Button type="submit" size="lg" block loading={loading} loadingText="Đang đặt lại…" disabled={codeDead || throttled}>
        Đặt lại mật khẩu
      </Button>

      {!codeDead ? (
        <div className="flex flex-col gap-3 border-t border-line pt-5 sm:flex-row sm:items-start sm:justify-between">
          <p className="text-sm text-ink-soft">Chưa nhận được mã?</p>
          <ResendCode key={wait.key} waitSeconds={wait.seconds} onResend={resend} loading={resending} />
        </div>
      ) : null}

      <Link href={routes.login} className="focus-ring w-fit rounded font-semibold text-primary hover:underline">
        Quay lại đăng nhập
      </Link>
    </form>
  );
}
