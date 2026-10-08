"use client";

import { useRef, useState } from "react";
import { Alert, ConfirmDialog } from "@vitaminvui/ui/v2";
import { lockStaff, resetStaffPassword, unlockStaff } from "@/lib/staff/api";
import { isStale, staffActionError } from "@/lib/staff/errors";
import type { StaffAccount, StaffWithPassword } from "@/lib/staff/types";

export type StaffAction = "lock" | "unlock" | "reset";

export type StaffActionResult = { action: "lock" | "unlock"; account: StaffAccount } | { action: "reset"; account: StaffWithPassword };

const COPY: Record<StaffAction, { title: (n: string) => string; description: (n: string) => string; confirm: string; loading: string; tone: "primary" | "danger" }> = {
  lock: {
    title: (n) => `Khóa tài khoản ${n}?`,
    description: () =>
      "Tài khoản này sẽ không đăng nhập được và bị từ chối truy cập ngay ở lượt thao tác tiếp theo (nếu đang có phiên hoạt động), cho tới khi được mở khóa lại. Phiên hiện tại bị huỷ: sau khi mở khóa vẫn phải đăng nhập lại.",
    confirm: "Khóa tài khoản",
    loading: "Đang khóa…",
    tone: "danger",
  },
  unlock: {
    title: (n) => `Mở khóa tài khoản ${n}?`,
    description: () => "Tài khoản này sẽ đăng nhập lại được bình thường.",
    confirm: "Mở khóa",
    loading: "Đang mở khóa…",
    tone: "primary",
  },
  reset: {
    title: (n) => `Đặt lại mật khẩu cho ${n}?`,
    description: (n) => `Hệ thống sẽ sinh mật khẩu mới, huỷ phiên đăng nhập hiện tại của ${n} (nếu có) và buộc đổi mật khẩu ở lần đăng nhập kế tiếp. Mật khẩu cũ hết hiệu lực ngay.`,
    confirm: "Đặt lại mật khẩu",
    loading: "Đang đặt lại…",
    tone: "danger",
  },
};

export interface StaffActionDialogProps {
  action: StaffAction;
  account: StaffAccount;
  /** Nên ổn định (useCallback). */
  onClose: () => void;
  onDone: (result: StaffActionResult) => void;
  /** Dữ liệu cũ (404 / đã xử lý rồi): màn hình tải lại danh sách. */
  onStale?: () => void;
}

/** Xác nhận Khóa / Mở khóa / Đặt lại mật khẩu (US-016 §2.6). Nút xác nhận `loading` ngay khi bấm (chặn bấm kép); lỗi server hiện trong hộp. */
export function StaffActionDialog({ action, account, onClose, onDone, onStale }: StaffActionDialogProps) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const pendingRef = useRef(false);
  const copy = COPY[action];

  async function confirm() {
    if (pendingRef.current) return;
    pendingRef.current = true;
    setPending(true);
    setError(null);
    try {
      if (action === "reset") onDone({ action, account: await resetStaffPassword(account.id) });
      else onDone({ action, account: await (action === "lock" ? lockStaff(account.id) : unlockStaff(account.id)) });
    } catch (err) {
      setError(staffActionError(err));
      if (isStale(err)) onStale?.();
    } finally {
      pendingRef.current = false;
      setPending(false);
    }
  }

  return (
    <ConfirmDialog
      open
      onClose={onClose}
      onConfirm={() => void confirm()}
      title={copy.title(account.name)}
      description={copy.description(account.name)}
      confirmLabel={copy.confirm}
      loadingText={copy.loading}
      tone={copy.tone}
      loading={pending}
    >
      <p className="break-all text-sm text-ink-soft">{account.email}</p>
      {error ? (
        <Alert tone="danger" className="mt-3">
          {error}
        </Alert>
      ) : null}
    </ConfirmDialog>
  );
}
