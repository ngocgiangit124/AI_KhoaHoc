"use client";

import { useEffect, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Alert, Button, Field, LoadingRegion, PasswordInput, Spinner, useToast } from "@vitaminvui/ui/v2";
import { changeStaffPassword } from "@/lib/auth/api";
import { classifyPasswordError } from "@/lib/auth/errors";
import { passwordChangeSchema, STAFF_PASSWORD_MIN_LENGTH, zodFieldErrors } from "@/lib/auth/schemas";
import { useSession } from "@/lib/auth/SessionProvider";
import { safeNext } from "@/lib/nav";

/** Màn buộc đổi mật khẩu lần đầu (US-016 §2.3) — không có menu, không có "Để sau". */
export function ForcePasswordChangeForm({ next }: { next?: string | null }) {
  const router = useRouter();
  const toast = useToast();
  const { state, refresh } = useSession();
  const target = safeNext(next);

  const [current, setCurrent] = useState("");
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  useEffect(() => {
    if (state.kind === "guest") router.replace("/dang-nhap");
    else if (state.kind === "mfa_required") router.replace(`/xac-thuc-mfa?next=${encodeURIComponent(target)}`);
    else if (state.kind === "staff") router.replace(target); // không còn bị buộc đổi
  }, [state, router, target]);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (pending) return;
    setBanner(null);

    const parsed = passwordChangeSchema.safeParse({ current_password: current, password, password_confirmation: confirmation });
    if (!parsed.success) {
      setFieldErrors(zodFieldErrors(parsed.error));
      return;
    }
    setFieldErrors({});
    setPending(true);

    try {
      await changeStaffPassword(parsed.data);
    } catch (err) {
      const failure = classifyPasswordError(err);
      if (failure.kind === "fields") {
        setFieldErrors(failure.fields);
        setBanner(failure.banner);
      } else {
        setBanner(failure.message);
      }
      setPending(false);
      return; // giữ nguyên dữ liệu đã nhập để sửa
    }

    toast.show({ tone: "success", title: "Đổi mật khẩu thành công" });
    const after = await refresh();
    // Backend huỷ phiên khác; nếu phiên hiện tại cũng mất thì phải đăng nhập lại.
    router.replace(after.kind === "guest" ? "/dang-nhap?reason=password_changed" : target);
    router.refresh();
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
  if (state.kind !== "password_change_required") {
    return <LoadingRegion className="flex items-center gap-2 text-sm text-ink-soft"><Spinner label={null} className="size-4" />Đang tải…</LoadingRegion>;
  }

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-5" aria-busy={pending}>
      {banner ? <Alert tone="danger">{banner}</Alert> : null}
      <fieldset disabled={pending} className="flex min-w-0 flex-col gap-4">
        <Field label="Mật khẩu hiện tại" required error={fieldErrors.current_password} hint="Mật khẩu tạm Admin cấp cho bạn.">
          <PasswordInput
            name="current_password"
            autoComplete="current-password"
            value={current}
            onChange={(e) => setCurrent(e.target.value)}
          />
        </Field>
        <Field
          label="Mật khẩu mới"
          required
          error={fieldErrors.password}
          hint={`Tối thiểu ${STAFF_PASSWORD_MIN_LENGTH} ký tự, không chứa phần trước @ của email.`}
        >
          <PasswordInput
            name="password"
            autoComplete="new-password"
            minLength={STAFF_PASSWORD_MIN_LENGTH}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </Field>
        <Field label="Xác nhận mật khẩu mới" required error={fieldErrors.password_confirmation}>
          <PasswordInput
            name="password_confirmation"
            autoComplete="new-password"
            value={confirmation}
            onChange={(e) => setConfirmation(e.target.value)}
          />
        </Field>
      </fieldset>
      <Button type="submit" size="lg" block loading={pending} loadingText="Đang lưu…">
        Đặt mật khẩu mới và tiếp tục
      </Button>
    </form>
  );
}
