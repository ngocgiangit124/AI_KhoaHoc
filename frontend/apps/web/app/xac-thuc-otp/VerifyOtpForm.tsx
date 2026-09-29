"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { Alert, Button, Countdown, OtpInput, Spinner, useToast } from "@vitaminvui/ui";
import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { authFetch } from "@/lib/api";
import { maskEmail, maskPhone } from "@/lib/auth/maskContact";
import { notifyAuthChanged } from "@/lib/auth/useCurrentUser";
import { parseAuthUser, type AuthUser } from "@/lib/types/auth";
import { parseOtpSendResponse } from "@/lib/types/otp";

export interface VerifyOtpFormProps {
  otpTtlMinutes: number;
  resendCooldownSeconds: number;
}

interface Banner {
  message: string;
  variant: "danger" | "warning" | "info";
}

/**
 * MVP chỉ gửi OTP qua kênh email (api-contract §2.2: "production MVP: chỉ `email`") — không
 * hiện lựa chọn kênh SMS ở form này.
 */
const OTP_CHANNEL = "email";

/**
 * Đối chiếu T04 thật (`RegisterController`, api-contract §2.2): `POST /auth/register` không
 * trả `resend_available_at` (chỉ `POST /auth/otp/send` mới trả) — đúng như giả định ban đầu.
 * Vì đăng ký đã tự gửi 1 OTP đầu tiên, màn này KHÔNG có mốc thời gian chính xác cho lần gửi
 * đó — suy ra hạn đếm ngược đầu tiên bằng `now + otp.resend_cooldown_seconds`
 * (`GET /config/public` → `otp.resend_cooldown_seconds`, khớp `OtpService::send()` dùng
 * `auth.otp.cooldown_seconds`) làm giá trị gần đúng, đủ để khoá nút "Gửi lại mã" trong thời
 * gian hợp lý. Từ lần bấm "Gửi lại mã" trở đi, dùng thẳng `resend_available_at` thật do server
 * trả (202) — 429 (vượt trần) dùng `Retry-After`/cooldown làm cận trên (xem `handleResend`).
 */
function computeFallbackResendAvailableAt(resendCooldownSeconds: number): string {
  return new Date(Date.now() + resendCooldownSeconds * 1000).toISOString();
}

function maskedContact(user: AuthUser): string | null {
  if (user.email) return maskEmail(user.email);
  if (user.phone) return maskPhone(user.phone);
  return null;
}

/**
 * Header `Retry-After` (giây, api-contract §1.7) chỉ dùng làm thông tin PHỤ cho thông báo khoá
 * (security review T04, giả định (d) đã sửa) — KHÔNG dùng làm nguồn chính cho cooldown gửi lại
 * mã (nguồn chính vẫn là `resend_available_at`). Làm tròn lên phút khi ≥ 60s cho dễ đọc.
 */
function formatRetryAfter(retryAfterSeconds: number): string {
  if (retryAfterSeconds < 60) {
    return `khoảng ${Math.ceil(retryAfterSeconds)} giây`;
  }
  return `khoảng ${Math.ceil(retryAfterSeconds / 60)} phút`;
}

function appendRetryAfterHint(message: string, retryAfterSeconds: number | undefined): string {
  if (retryAfterSeconds === undefined) return message;
  return `${message} (thử lại sau ${formatRetryAfter(retryAfterSeconds)})`;
}

export function VerifyOtpForm({ otpTtlMinutes, resendCooldownSeconds }: VerifyOtpFormProps) {
  const router = useRouter();
  const toast = useToast();

  const [user, setUser] = useState<AuthUser | null>(null);
  const [loadingMe, setLoadingMe] = useState(true);
  const [meError, setMeError] = useState<string | null>(null);

  const [code, setCode] = useState("");
  const [otpError, setOtpError] = useState<string | null>(null);
  const [banner, setBanner] = useState<Banner | null>(null);
  const [isVerifying, setIsVerifying] = useState(false);
  const [isResending, setIsResending] = useState(false);
  const [locked, setLocked] = useState(false);
  const [resendAvailableAt, setResendAvailableAt] = useState<string | null>(null);
  const [canResendNow, setCanResendNow] = useState(false);

  useEffect(() => {
    let cancelled = false;

    authFetch<unknown>("/api/v1/auth/me")
      .then((raw) => {
        if (cancelled) return;
        const parsed = parseAuthUser(raw);
        setUser(parsed);
        setLoadingMe(false);
        if (parsed.is_verified !== true) {
          setResendAvailableAt(computeFallbackResendAvailableAt(resendCooldownSeconds));
        }
      })
      .catch((err) => {
        if (cancelled) return;
        setLoadingMe(false);
        // 401 UNAUTHENTICATED: `authFetch` đã phát sự kiện `login-required` (không
        // suppressAuthEvents) — `ForcedLogoutOverlay` ở layout gốc tự chuyển hướng
        // `/dang-nhap?next=/xac-thuc-otp`. Chỉ cần hiện thông báo tạm trong lúc chuyển trang.
        if (err instanceof ApiError) {
          setMeError(err.message);
          return;
        }
        setMeError("Không tải được thông tin tài khoản, vui lòng thử lại.");
      });

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- chỉ chạy 1 lần lúc mount.
  }, []);

  useEffect(() => {
    if (user?.is_verified === true) {
      toast.show("info", "Tài khoản của bạn đã được xác thực.");
      router.replace("/");
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user?.is_verified]);

  const handleVerify = useCallback(
    async (submittedCode: string) => {
      if (submittedCode.length !== 6 || locked) return;

      setIsVerifying(true);
      setOtpError(null);
      setBanner(null);

      try {
        const raw = await authFetch<unknown>("/api/v1/auth/otp/verify", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ code: submittedCode }),
        });
        parseAuthUser(raw);

        toast.show("success", "Xác thực tài khoản thành công");
        notifyAuthChanged();
        router.push("/");
        return;
      } catch (err) {
        if (err instanceof ApiError) {
          if (err.status === 429) {
            setLocked(true);
            // api-contract §1.7 — `Retry-After` chỉ là thông tin PHỤ đính kèm thông báo khoá
            // (message chính do server soạn, vd "Tài khoản tạm khoá xác thực trong 24 giờ.").
            setBanner({ message: appendRetryAfterHint(err.message, err.retryAfterSeconds), variant: "warning" });
          } else {
            // 422 mã sai/hết hạn (api-contract §1.7 VALIDATION_ERROR) — message tiếng Việt
            // do server trả (US-001 bảng trạng thái: "Mã OTP không đúng"/"Mã OTP đã hết hạn").
            setOtpError(err.message);
          }
          setCode("");
          return;
        }
        if (err instanceof NetworkError) {
          setBanner({ message: err.message, variant: "danger" });
          return;
        }
        setBanner({ message: "Đã có lỗi xảy ra, vui lòng thử lại sau.", variant: "danger" });
      } finally {
        setIsVerifying(false);
      }
    },
    [locked, router, toast],
  );

  async function handleResend() {
    setIsResending(true);
    setBanner(null);
    setOtpError(null);

    try {
      const raw = await authFetch<unknown>("/api/v1/auth/otp/send", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ channel: OTP_CHANNEL }),
      });
      const parsed = parseOtpSendResponse(raw);
      setResendAvailableAt(parsed.resend_available_at);
      setCanResendNow(false);
      toast.show("info", "Đã gửi lại mã xác thực.");
    } catch (err) {
      if (err instanceof ApiError) {
        // api-contract §1.6: `otp-send` chỉ giới hạn cooldown/tần suất gửi (60s, 5/giờ,
        // 10/ngày) — KHÔNG khoá xác thực 24h như `otp-verify`. 429 ở đây chỉ cần banner, vẫn
        // cho thử "Xác nhận" với mã đã có (nếu còn hiệu lực).
        //
        // T04 security review (docs/security/review-T04.md §L3, khuyến nghị dòng 179) —
        // response lỗi của `POST /auth/otp/send` KHÔNG có `resend_available_at` (chỉ 202
        // thành công mới có). Ưu tiên `Retry-After` (có ở mọi 429 từ sau bản sửa L3, cả lớp
        // Service lẫn route throttle); nếu vì lý do nào đó vẫn thiếu, dùng
        // `otp.resend_cooldown_seconds` (`config/public`) làm cận trên hợp lý — không để nút
        // "Gửi lại mã" hiện lại NGAY LẬP TỨC sau khi vừa bị 429 (bấm dồn dập vô ích).
        if (err.status === 429) {
          const fallbackSeconds = err.retryAfterSeconds ?? resendCooldownSeconds;
          setResendAvailableAt(new Date(Date.now() + fallbackSeconds * 1000).toISOString());
          setCanResendNow(false);
        }
        setBanner({ message: appendRetryAfterHint(err.message, err.retryAfterSeconds), variant: err.status === 429 ? "warning" : "danger" });
        return;
      }
      if (err instanceof NetworkError) {
        setBanner({ message: err.message, variant: "danger" });
        return;
      }
      setBanner({ message: "Đã có lỗi xảy ra, vui lòng thử lại sau.", variant: "danger" });
    } finally {
      setIsResending(false);
    }
  }

  if (loadingMe) {
    return (
      <div className="flex justify-center py-10">
        <Spinner />
      </div>
    );
  }

  if (meError && !user) {
    return <Alert variant="danger">{meError}</Alert>;
  }

  if (!user || user.is_verified === true) {
    // Đang chuyển hướng (đã xác thực rồi) — không render form để tránh nháy giao diện.
    return null;
  }

  const contact = maskedContact(user);

  return (
    <div className="rounded-2xl border border-gray-100 bg-white p-6 text-center shadow-sm">
      <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-indigo-50 text-2xl text-indigo-600">
        ✉️
      </div>
      <h1 className="mb-1 text-xl font-bold text-gray-900">Xác thực tài khoản</h1>
      <p className="mb-6 text-sm text-gray-500">
        Chúng tôi đã gửi mã gồm 6 chữ số tới{" "}
        {contact ? <span className="font-medium text-gray-700">{contact}</span> : "địa chỉ liên hệ của bạn"}. Mã có
        hiệu lực trong {otpTtlMinutes} phút.
      </p>

      {banner ? (
        <div className="mb-4 text-left">
          <Alert variant={banner.variant}>{banner.message}</Alert>
        </div>
      ) : null}

      <div className="mb-6">
        <OtpInput
          value={code}
          onChange={(value) => {
            setCode(value);
            setOtpError(null);
          }}
          onComplete={handleVerify}
          error={otpError ?? undefined}
          disabled={isVerifying || locked}
        />
      </div>

      <Button
        type="button"
        variant="primary"
        size="lg"
        className="mb-3 w-full"
        loading={isVerifying}
        disabled={code.length !== 6 || isVerifying || locked}
        onClick={() => handleVerify(code)}
      >
        {isVerifying ? "Đang xác nhận..." : "Xác nhận"}
      </Button>

      <p className="text-sm text-gray-500">
        Chưa nhận được mã?{" "}
        {locked ? (
          <span className="text-gray-400">Tài khoản tạm khoá xác thực, vui lòng liên hệ hỗ trợ.</span>
        ) : resendAvailableAt && !canResendNow ? (
          <span className="text-gray-400">
            Gửi lại sau{" "}
            <Countdown targetTime={resendAvailableAt} onExpire={() => setCanResendNow(true)} />
          </span>
        ) : (
          <button
            type="button"
            className="font-medium text-indigo-600 disabled:cursor-not-allowed disabled:text-gray-400"
            onClick={handleResend}
            disabled={isResending}
          >
            {isResending ? "Đang gửi..." : "Gửi lại mã"}
          </button>
        )}
      </p>
    </div>
  );
}
