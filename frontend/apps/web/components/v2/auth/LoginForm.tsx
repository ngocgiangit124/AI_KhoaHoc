"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";
import { Alert, Button, Field, PasswordInput, TextInput, useToast } from "@vitaminvui/ui/v2";
import { routes } from "@/lib/v2/routes";

export type LoginNotice = "sai" | "qua-nhieu" | "khoa" | "thiet-bi" | "het-phien" | "dat-lai-xong";

const NOTICES: Record<LoginNotice, { tone: "danger" | "warning" | "info" | "success"; title: string; body?: string }> = {
  // 422 VALIDATION_ERROR (field login) — thông điệp chung, không nói ô nào sai (US-001 BR5).
  sai: { tone: "danger", title: "Thông tin đăng nhập hoặc mật khẩu không đúng." },
  // 429 TOO_MANY_ATTEMPTS + Retry-After.
  "qua-nhieu": { tone: "warning", title: "Bạn đã nhập sai nhiều lần.", body: "Vui lòng thử lại sau 15 phút, hoặc đặt lại mật khẩu." },
  // 403 ACCOUNT_LOCKED.
  khoa: { tone: "danger", title: "Tài khoản của bạn đã bị khoá.", body: "Vui lòng liên hệ bộ phận hỗ trợ để được giúp đỡ." },
  // Chuyển về từ ForcedLogoutOverlay (SESSION_REPLACED).
  "thiet-bi": { tone: "info", title: "Bạn đã đăng nhập trên một thiết bị khác.", body: "Mỗi tài khoản chỉ học trên một thiết bị cùng lúc. Đăng nhập lại để tiếp tục học ở đây." },
  // login-required (SESSION_EXPIRED/REVOKED): êm hơn, không báo động.
  "het-phien": { tone: "info", title: "Phiên đăng nhập đã hết hạn.", body: "Đăng nhập lại để tiếp tục — bạn sẽ quay về đúng trang đang xem." },
  // Sau POST /auth/password/reset 200 (không tự đăng nhập — US-015 AC2).
  "dat-lai-xong": { tone: "success", title: "Đặt lại mật khẩu thành công.", body: "Đăng nhập bằng mật khẩu mới. Các thiết bị khác đã được đăng xuất." },
};

/** Form đăng nhập học sinh (POST /auth/login). TODO(dev): nối api, giữ `?next=` qua safeRedirect. */
export function LoginForm({ notice }: { notice?: LoginNotice }) {
  const toast = useToast();
  const [loading, setLoading] = useState(false);
  const [errors, setErrors] = useState<{ login?: string; password?: string }>({});

  function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const data = new FormData(e.currentTarget);
    const next: typeof errors = {};
    if (!String(data.get("login") ?? "").trim()) next.login = "Vui lòng nhập email hoặc số điện thoại.";
    if (!String(data.get("password") ?? "")) next.password = "Vui lòng nhập mật khẩu.";
    setErrors(next);
    if (Object.keys(next).length) {
      (e.currentTarget.elements.namedItem(next.login ? "login" : "password") as HTMLInputElement | null)?.focus();
      return;
    }
    setLoading(true);
    setTimeout(() => {
      setLoading(false);
      toast.show({ tone: "info", title: "Bản xem trước", description: "Chưa nối API đăng nhập." });
    }, 900);
  }

  const n = notice ? NOTICES[notice] : null;
  return (
    <form noValidate onSubmit={onSubmit} className="flex flex-col gap-5">
      {n ? (
        <Alert tone={n.tone} title={n.title}>
          {n.body}
        </Alert>
      ) : null}
      <Field label="Email hoặc số điện thoại" required error={errors.login}>
        <TextInput name="login" autoComplete="username" inputMode="email" placeholder="ví dụ: minhanh@gmail.com" />
      </Field>
      <Field
        label="Mật khẩu"
        required
        error={errors.password}
        aside={
          <Link href={routes.forgot} className="focus-ring rounded font-semibold text-primary hover:underline">
            Quên mật khẩu?
          </Link>
        }
      >
        <PasswordInput name="password" autoComplete="current-password" />
      </Field>
      <Button type="submit" size="lg" block loading={loading} loadingText="Đang đăng nhập…">
        Đăng nhập
      </Button>
      <p className="text-center text-base text-ink-soft">
        Chưa có tài khoản?{" "}
        <Link href={routes.register} className="focus-ring rounded font-semibold text-primary hover:underline">
          Đăng ký ngay
        </Link>
      </p>
    </form>
  );
}
