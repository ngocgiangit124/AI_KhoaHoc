"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { ApiError, NetworkError, isStaleDocument, loginNeedsCaptcha, safeRedirect } from "@vitaminvui/api-client";
import { TurnstileWidget } from "@vitaminvui/ui";
import { Alert, Button, Field, PasswordInput, TextInput } from "@vitaminvui/ui/v2";
import { loginStudent } from "@/lib/auth/api";
import { AppLink } from "@/components/shell/AppLink";
import { clearResetLogin } from "@/lib/auth/flash";
import { retryAfterText, UNKNOWN_ERROR_MESSAGE } from "@/lib/auth/errors";
import type { LoginNotice } from "@/lib/auth/loginNotice";
import { loginSchema, type LoginFormValues } from "@/lib/auth/schemas";
import { isCaptchaHref, routes } from "@/lib/routes";

/** Sau khi tải lại tài liệu (CSP đúng) chỉ khôi phục định danh đã nhập (KHÔNG mật khẩu, KHÔNG token) và cờ "đang bị đòi captcha". */
const RELOAD_KEY = "vv:gla2-login";
/** Widget không bắn callback trong chừng này thì coi như không tải được (CSP cũ, chặn mạng) và gợi ý tải lại trang. */
const WIDGET_TIMEOUT_MS = 10_000;

type Tone = "danger" | "warning" | "info" | "success";
interface Banner {
  tone: Tone;
  title: string;
  body?: string;
}

const NOTICES: Record<LoginNotice, Banner> = {
  "het-phien": { tone: "info", title: "Phiên đăng nhập đã hết hạn.", body: "Đăng nhập lại để tiếp tục — bạn sẽ quay về đúng trang đang xem." },
  "dat-lai-xong": {
    tone: "success",
    title: "Đặt lại mật khẩu thành công.",
    body: "Đăng nhập bằng mật khẩu mới. Các thiết bị khác đã được đăng xuất.",
  },
};

/** Lỗi đăng nhập -> banner (US-001 BR5: 422 là thông điệp chung, không nói ô nào sai; design-system-v2 §12.5). */
export function loginBanner(err: unknown): Banner {
  if (err instanceof NetworkError) return { tone: "danger", title: err.message };
  if (err instanceof ApiError) {
    if (err.code === "ACCOUNT_LOCKED") {
      return { tone: "danger", title: "Tài khoản của bạn đã bị khoá.", body: "Vui lòng liên hệ bộ phận hỗ trợ để được giúp đỡ." };
    }
    if (err.code === "CAPTCHA_REQUIRED") {
      return { tone: "warning", title: "Bạn đã nhập sai nhiều lần.", body: "Vui lòng hoàn tất xác minh chống spam bên dưới rồi đăng nhập lại." };
    }
    if (err.code === "CAPTCHA_INVALID") {
      return { tone: "danger", title: "Xác minh chống spam không hợp lệ hoặc đã hết hạn.", body: "Vui lòng xác minh lại bên dưới rồi đăng nhập lại." };
    }
    if (err.status === 422) {
      return {
        tone: "danger",
        title: "Thông tin đăng nhập hoặc mật khẩu không đúng.",
        ...(err.captchaRequired ? { body: "Vui lòng hoàn tất xác minh chống spam bên dưới trước khi thử lại." } : {}),
      };
    }
    if (err.status === 429 || err.code === "TOO_MANY_ATTEMPTS") {
      return { tone: "warning", title: "Bạn đã nhập sai nhiều lần.", body: `${retryAfterText(err.retryAfterSeconds)} Hoặc đặt lại mật khẩu.` };
    }
    // WRONG_PORTAL và lỗi khác: thông điệp tiếng Việt do server trả.
    return { tone: "danger", title: err.message || UNKNOWN_ERROR_MESSAGE };
  }
  return { tone: "danger", title: UNKNOWN_ERROR_MESSAGE };
}

export interface LoginFormProps {
  /** Giá trị thô của `?next=` — được validate bằng `safeRedirect` (chống open redirect). */
  next?: string | null;
  /** Thông báo từ trang khác (hết phiên, vừa đặt lại mật khẩu). */
  notice?: LoginNotice;
  /** Site key Turnstile (GL-A2). `null` = chưa cấu hình (local): không hiện widget, không gửi `captcha_token`. */
  captchaSiteKey?: string | null;
}

/** Form `/dang-nhap` (US-001 §2.3) trên design v2. Quên mật khẩu: `/quen-mat-khau` (US-015). */
export function LoginForm({ next, notice, captchaSiteKey = null }: LoginFormProps) {
  const router = useRouter();
  const [banner, setBanner] = useState<Banner | null>(null);
  const [done, setDone] = useState(false);
  // GL-A2: sau khi sai nhiều lần server đòi captcha; token dùng 1 lần nên đổi `captchaKey` để mount widget mới sau MỖI lần gửi.
  const [captchaNeeded, setCaptchaNeeded] = useState(false);
  const [captchaToken, setCaptchaToken] = useState<string | null>(null);
  const [captchaKey, setCaptchaKey] = useState(0);
  const [captchaBroken, setCaptchaBroken] = useState(false);
  const siteKey = captchaSiteKey && captchaSiteKey.trim() !== "" ? captchaSiteKey : null;
  const showWidget = captchaNeeded && siteKey !== null;

  const {
    register,
    handleSubmit,
    resetField,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { login: "", password: "" },
  });

  const locked = isSubmitting || done;

  // Quay lại sau khi tải lại tài liệu vì CSP cũ: khôi phục định danh, hiện luôn widget.
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
    setBanner(loginBanner(new ApiError(422, { message: "", code: "CAPTCHA_REQUIRED" })));
    if (saved) setValue("login", saved);
  }, [setValue]);

  // Widget không bắn callback (script/iframe bị chặn): sau 10 giây gợi ý tải lại trang.
  useEffect(() => {
    if (!showWidget || captchaToken !== null || captchaBroken) return;
    const t = setTimeout(() => setCaptchaBroken(true), WIDGET_TIMEOUT_MS);
    return () => clearTimeout(t);
  }, [showWidget, captchaToken, captchaBroken, captchaKey]);

  // Vừa đặt lại mật khẩu xong: bỏ định danh đã giữ ở bước 2 (không dọn ở trang bước 2 để khỏi nháy màn "chưa yêu cầu mã").
  useEffect(() => {
    if (notice === "dat-lai-xong") clearResetLogin();
  }, [notice]);

  const onSubmit = handleSubmit(async (values) => {
    setBanner(null);
    try {
      await loginStudent({ ...values, captchaToken: showWidget ? captchaToken : null });
      clearResetLogin();
      setDone(true);
      const target = safeRedirect(next, "/");
      // Trang có captcha cần tải lại tài liệu để nhận CSP cho Turnstile (FW1 R1/R14).
      if (isCaptchaHref(target)) {
        window.location.assign(target);
        return;
      }
      router.replace(target);
      router.refresh();
    } catch (err) {
      // Trang đến bằng điều hướng mềm thì CSP là của trang trước, iframe Turnstile bị chặn: tải lại tài liệu (giữ định danh).
      if (loginNeedsCaptcha(err) && isStaleDocument()) {
        try {
          sessionStorage.setItem(RELOAD_KEY, values.login.trim());
        } catch {
          /* bỏ qua */
        }
        window.location.reload();
        return;
      }
      setBanner(loginBanner(err));
      resetField("password", { defaultValue: "" });
      if (loginNeedsCaptcha(err)) setCaptchaNeeded(true);
      // Token đã dùng (hoặc bị từ chối): bỏ và lấy token mới.
      setCaptchaToken(null);
      setCaptchaBroken(false);
      setCaptchaKey((k) => k + 1);
    }
  });

  function onCaptchaToken(token: string | null) {
    setCaptchaToken(token);
    if (token) setCaptchaBroken(false); // widget tự thử lại thành công
  }

  const shown = banner ?? (notice ? NOTICES[notice] : null);

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-5" aria-busy={locked}>
      {shown ? (
        <Alert tone={shown.tone} title={shown.title} role={shown.tone === "danger" || shown.tone === "warning" ? "alert" : "status"}>
          {shown.body}
        </Alert>
      ) : null}

      <fieldset disabled={locked} className="flex flex-col gap-5">
        <Field label="Email hoặc số điện thoại" required error={errors.login?.message}>
          <TextInput autoComplete="username" inputMode="email" placeholder="ví dụ: minhanh@gmail.com" {...register("login")} />
        </Field>
        <Field
          label="Mật khẩu"
          required
          error={errors.password?.message}
          aside={
            <AppLink href={routes.forgotPassword} className="focus-ring inline-flex min-h-11 items-center rounded font-semibold text-primary hover:underline">
              Quên mật khẩu?
            </AppLink>
          }
        >
          <PasswordInput autoComplete="current-password" {...register("password")} />
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
        <Alert tone="warning" role="alert" title="Chưa thể xác minh chống spam lúc này.">
          Vui lòng thử lại sau ít phút hoặc đặt lại mật khẩu.
        </Alert>
      ) : null}

      <Button type="submit" size="lg" block loading={locked} loadingText="Đang đăng nhập…" disabled={showWidget && captchaToken === null} aria-describedby={showWidget && captchaToken === null ? "captcha-hint" : undefined}>
        Đăng nhập
      </Button>

      <p className="text-center text-base text-ink-soft">
        Chưa có tài khoản?{" "}
        <AppLink href={routes.register} className="focus-ring inline-flex min-h-11 items-center rounded font-semibold text-primary hover:underline">
          Đăng ký ngay
        </AppLink>
      </p>
    </form>
  );
}
