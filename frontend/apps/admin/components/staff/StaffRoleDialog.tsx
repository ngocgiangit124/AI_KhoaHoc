"use client";

import { useRef, useState } from "react";
import { Alert, ConfirmDialog, Field, Select } from "@vitaminvui/ui/v2";
import { STAFF_ROLES, STAFF_ROLE_LABELS, type StaffRole } from "@/lib/auth/types";
import { changeStaffRole } from "@/lib/staff/api";
import { isStale, staffActionError } from "@/lib/staff/errors";
import type { StaffAccount, StaffRoleResult } from "@/lib/staff/types";

export interface StaffRoleDialogProps {
  account: StaffAccount;
  /** Nên ổn định (useCallback). */
  onClose: () => void;
  onDone: (result: StaffRoleResult) => void;
  onStale?: () => void;
}

/** Đổi vai trò (PATCH /admin/staff/{id}/role). Hạ một giáo viên → cảnh báo trước: các khóa họ phụ trách sẽ bị gỡ. Lỗi server (tự đổi, Admin cuối...) hiện trong hộp. */
export function StaffRoleDialog({ account, onClose, onDone, onStale }: StaffRoleDialogProps) {
  const [role, setRole] = useState<StaffRole>(account.role);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const pendingRef = useRef(false);
  const changed = role !== account.role;
  const dropsCourses = account.role === "giao_vien" && changed;
  const adminChange = changed && (role === "admin" || account.role === "admin");

  async function confirm() {
    if (pendingRef.current) return;
    if (!changed) {
      setError("Hãy chọn vai trò khác với vai trò hiện tại.");
      return;
    }
    pendingRef.current = true;
    setPending(true);
    setError(null);
    try {
      onDone(await changeStaffRole(account.id, role));
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
      title={`Đổi vai trò của ${account.name}`}
      description={`Hiện là ${STAFF_ROLE_LABELS[account.role]}. Phiên đăng nhập hiện tại của người này bị huỷ, phải đăng nhập lại (xác thực 2 lớp theo vai trò mới).`}
      confirmLabel="Đổi vai trò"
      loadingText="Đang đổi…"
      tone={dropsCourses || adminChange ? "danger" : "primary"}
      loading={pending}
    >
      <div className="flex flex-col gap-3">
        <Field label="Vai trò mới" required>
          <Select
            name="role"
            value={role}
            disabled={pending}
            onChange={(e) => {
              setRole(e.target.value as StaffRole);
              setError(null);
            }}
          >
            {STAFF_ROLES.map((r) => (
              <option key={r} value={r}>
                {STAFF_ROLE_LABELS[r]}
              </option>
            ))}
          </Select>
        </Field>
        {dropsCourses ? (
          <Alert tone="warning" title="Các khóa đang phụ trách sẽ bị gỡ">
            Người này sẽ không còn là giáo viên phụ trách của mọi khóa học đang được gán. Khóa có thể không còn giáo viên cho tới khi bạn gán lại. Đổi ngược về Giáo viên cũng không tự gán lại khóa.
          </Alert>
        ) : null}
        {adminChange ? (
          <Alert tone="danger" title="Vai trò Admin có toàn quyền hệ thống">
            {role === "admin"
              ? "Admin quản lý được mọi tài khoản staff, dữ liệu học sinh và cấu hình hệ thống. Chỉ cấp cho người thật sự cần."
              : "Người này sẽ mất toàn quyền hệ thống (tài khoản staff, dữ liệu học sinh, cấu hình)."}
          </Alert>
        ) : null}
        {error ? <Alert tone="danger">{error}</Alert> : null}
      </div>
    </ConfirmDialog>
  );
}
