"use client";

import { useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Alert, Button, Field, PasswordInput, useToast } from "@vitaminvui/ui/v2";
import { changePassword } from "@/lib/auth/api";
import { useAuth } from "@/lib/auth/AuthProvider";
import { classifyChangePasswordError } from "@/lib/auth/errors";
import { focusFirstError } from "@/lib/focus";
import { routes } from "@/lib/routes";

type Errors = Partial<Record<"current_password" | "password" | "password_confirmation", string>>;

/**
 * Đổi mật khẩu khi đã đăng nhập (`PUT /auth/password`, US-015 AC4/AC5): min 8, khác mật khẩu cũ, chặn mật khẩu phổ biến
 * (server trả `errors.password[0]`, hiện nguyên văn). Thành công: phiên hiện tại giữ lại, thiết bị khác bị đăng xuất.
 * `session_kept=false` (server không bind lại được phiên): hỏi lại `/auth/me`, mất phiên thì về đăng nhập.
 */
export function ChangePasswordForm() {
  const router = useRouter();
  const toast = useToast();
  const { refresh } = useAuth();
  const [errors, setErrors] = useState<Errors>({});
  const [banner, setBanner] = useState<string | null>(null);
  const [throttle, setThrottle] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const [values, setValues] = useState({ current_password: "", password: "", password_confirmation: "" });

  const set = (k: keyof typeof values) => (e: { target: { value: string } }) => setValues((v) => ({ ...v, [k]: e.target.value }));

  const focusNew = (e: Errors) =>
    focusFirstError([
      ["pw-current", !!e.current_password],
      ["pw-new", !!e.password],
      ["pw-confirm", !!e.password_confirmation],
    ]);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setBanner(null);
    const next: Errors = {};
    if (!values.current_password) next.current_password = "Vui lòng nhập mật khẩu hiện tại.";
    if (values.password.length < 8) next.password = "Mật khẩu mới cần tối thiểu 8 ký tự.";
    if (values.password_confirmation !== values.password) next.password_confirmation = "Xác nhận mật khẩu không khớp.";
    setErrors(next);
    if (Object.keys(next).length > 0) {
      focusNew(next);
      return;
    }

    setPending(true);
    setThrottle(null);
    try {
      const { sessionKept } = await changePassword(values);
      setValues({ current_password: "", password: "", password_confirmation: "" });
      if (!sessionKept) {
        const state = await refresh();
        if (state.status === "guest") {
          router.replace(`${routes.login}?next=${routes.account}`);
          return;
        }
      }
      toast.show({ tone: "success", title: "Đã đổi mật khẩu", description: "Các thiết bị khác đã được đăng xuất." });
    } catch (err) {
      const failure = classifyChangePasswordError(err);
      // Không giữ mật khẩu hiện tại sau lỗi: nhập lại (mật khẩu mới giữ để khỏi gõ lại).
      setValues((v) => ({ ...v, current_password: "" }));
      if (failure.kind === "fields") {
        setErrors(failure.errors);
        focusNew(failure.errors);
      }
      else if (failure.kind === "throttled") setThrottle(failure.message);
      else setBanner(failure.message);
    } finally {
      setPending(false);
    }
  }

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-5" aria-busy={pending}>
      {throttle ? (
        <Alert tone="warning" title="Bạn đã thử quá nhiều lần">
          {throttle}
        </Alert>
      ) : null}
      {banner ? <Alert tone="danger">{banner}</Alert> : null}
      <Field id="pw-current" label="Mật khẩu hiện tại" required error={errors.current_password}>
        <PasswordInput autoComplete="current-password" value={values.current_password} onChange={set("current_password")} disabled={pending} />
      </Field>
      <Field id="pw-new" label="Mật khẩu mới" required error={errors.password} hint="Tối thiểu 8 ký tự, khác mật khẩu hiện tại. Tránh mật khẩu dễ đoán như 12345678.">
        <PasswordInput autoComplete="new-password" maxLength={128} value={values.password} onChange={set("password")} disabled={pending} />
      </Field>
      <Field id="pw-confirm" label="Nhập lại mật khẩu mới" required error={errors.password_confirmation}>
        <PasswordInput autoComplete="new-password" maxLength={128} value={values.password_confirmation} onChange={set("password_confirmation")} disabled={pending} />
      </Field>
      <div>
        <Button type="submit" loading={pending} loadingText="Đang đổi…">
          Đổi mật khẩu
        </Button>
      </div>
    </form>
  );
}
