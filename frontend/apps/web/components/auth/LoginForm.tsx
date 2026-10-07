"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { ApiError, NetworkError, safeRedirect } from "@vitaminvui/api-client";
import { Alert, Button, Field, PasswordInput, TextInput } from "@vitaminvui/ui/v2";
import { loginStudent } from "@/lib/auth/api";
import { AppLink } from "@/components/shell/AppLink";
import { clearResetLogin } from "@/lib/auth/flash";
import { retryAfterText, UNKNOWN_ERROR_MESSAGE } from "@/lib/auth/errors";
import type { LoginNotice } from "@/lib/auth/loginNotice";
import { loginSchema, type LoginFormValues } from "@/lib/auth/schemas";
import { isCaptchaHref, routes } from "@/lib/routes";

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
    if (err.status === 422) return { tone: "danger", title: "Thông tin đăng nhập hoặc mật khẩu không đúng." };
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
}

/** Form `/dang-nhap` (US-001 §2.3) trên design v2. Quên mật khẩu: `/quen-mat-khau` (US-015). */
export function LoginForm({ next, notice }: LoginFormProps) {
  const router = useRouter();
  const [banner, setBanner] = useState<Banner | null>(null);
  const [done, setDone] = useState(false);

  const {
    register,
    handleSubmit,
    resetField,
    formState: { errors, isSubmitting },
  } = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { login: "", password: "" },
  });

  const locked = isSubmitting || done;

  // Vừa đặt lại mật khẩu xong: bỏ định danh đã giữ ở bước 2 (không dọn ở trang bước 2 để khỏi nháy màn "chưa yêu cầu mã").
  useEffect(() => {
    if (notice === "dat-lai-xong") clearResetLogin();
  }, [notice]);

  const onSubmit = handleSubmit(async (values) => {
    setBanner(null);
    try {
      await loginStudent(values);
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
      setBanner(loginBanner(err));
      resetField("password", { defaultValue: "" });
    }
  });

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

      <Button type="submit" size="lg" block loading={locked} loadingText="Đang đăng nhập…">
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
