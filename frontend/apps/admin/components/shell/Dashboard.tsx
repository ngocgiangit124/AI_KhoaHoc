"use client";

import { Alert } from "@vitaminvui/ui/v2";
import { useSession } from "@/lib/auth/SessionProvider";
import { STAFF_ROLE_LABELS } from "@/lib/auth/types";

/** Trang tổng quan tạm của FA1 (các màn nghiệp vụ làm ở FA2+). */
export function Dashboard() {
  const { state } = useSession();
  if (state.kind !== "staff") return null;
  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-title font-extrabold tracking-heading text-ink">Xin chào, {state.user.name}</h1>
      <Alert tone="info" role="none">
        Bạn đang đăng nhập với vai trò <strong>{STAFF_ROLE_LABELS[state.user.role]}</strong>. Các chức năng quản lý sẽ
        xuất hiện ở menu bên trái khi được mở.
      </Alert>
    </div>
  );
}
