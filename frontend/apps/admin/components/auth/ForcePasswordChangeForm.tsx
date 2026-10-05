"use client";

import { useEffect, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Alert, Button, FormField, PasswordInput, useToast } from "@vitaminvui/ui";
import { changeStaffPassword } from "@/lib/auth/api";
import { classifyPasswordError } from "@/lib/auth/errors";
import { passwordChangeSchema, zodFieldErrors } from "@/lib/auth/schemas";
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

    toast.show("success", "Đổi mật khẩu thành công");
    const after = await refresh();
    // Backend huỷ phiên khác; nếu phiên hiện tại cũng mất thì phải đăng nhập lại.
    router.replace(after.kind === "guest" ? "/dang-nhap?reason=password_changed" : target);
    router.refresh();
  }

  if (state.kind === "error") {
    return (
      <div className="space-y-4">
        <Alert variant="danger">Không kiểm tra được phiên đăng nhập. Vui lòng kiểm tra kết nối và thử lại.</Alert>
        <Button type="button" onClick={() => void refresh()}>
          Thử lại
        </Button>
      </div>
    );
  }
  if (state.kind === "locked") {
    return <Alert variant="danger">Tài khoản của bạn đã bị khóa. Vui lòng liên hệ Admin.</Alert>;
  }
  if (state.kind !== "password_change_required") {
    return <p className="text-sm text-gray-700">Đang tải…</p>;
  }

  return (
    <form onSubmit={onSubmit} noValidate className="space-y-4" aria-busy={pending}>
      {banner ? <Alert variant="danger">{banner}</Alert> : null}
      <fieldset disabled={pending} className="space-y-4">
        <FormField label="Mật khẩu hiện tại" required error={fieldErrors.current_password} hint="Mật khẩu tạm Admin cấp cho bạn.">
          <PasswordInput
            name="current_password"
            autoComplete="current-password"
            value={current}
            onChange={(e) => setCurrent(e.target.value)}
          />
        </FormField>
        <FormField label="Mật khẩu mới" required error={fieldErrors.password} hint="Tối thiểu 8 ký tự.">
          <PasswordInput
            name="password"
            autoComplete="new-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </FormField>
        <FormField label="Xác nhận mật khẩu mới" required error={fieldErrors.password_confirmation}>
          <PasswordInput
            name="password_confirmation"
            autoComplete="new-password"
            value={confirmation}
            onChange={(e) => setConfirmation(e.target.value)}
          />
        </FormField>
      </fieldset>
      <Button type="submit" size="lg" className="w-full" loading={pending}>
        Đặt mật khẩu mới và tiếp tục
      </Button>
    </form>
  );
}
