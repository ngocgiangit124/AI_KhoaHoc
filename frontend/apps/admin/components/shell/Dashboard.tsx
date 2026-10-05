"use client";

import { Card } from "@vitaminvui/ui";
import { useSession } from "@/lib/auth/SessionProvider";
import { STAFF_ROLE_LABELS } from "@/lib/auth/types";

/** Trang tổng quan tạm của FA1 (các màn nghiệp vụ làm ở FA2+). */
export function Dashboard() {
  const { state } = useSession();
  if (state.kind !== "staff") return null;
  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold text-gray-900">Xin chào, {state.user.name}</h1>
      <Card>
        <p className="text-sm text-gray-700">
          Bạn đang đăng nhập với vai trò <strong>{STAFF_ROLE_LABELS[state.user.role]}</strong>. Các chức năng quản lý
          sẽ xuất hiện ở menu bên trái khi được mở.
        </p>
      </Card>
    </div>
  );
}
