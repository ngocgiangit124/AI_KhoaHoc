"use client";

import type { ReactNode } from "react";
import { useSession } from "@/lib/auth/SessionProvider";
import type { StaffRole } from "@/lib/auth/types";
import { ForbiddenView } from "./ForbiddenView";

/**
 * Bọc nội dung chỉ dành cho một số vai trò (ví dụ `/quan-tri/tai-khoan` chỉ admin). Chỉ để trải
 * nghiệm — API vẫn trả 403 `FORBIDDEN` nếu gọi thẳng. Dùng bên trong `AuthGate`.
 */
export function RequireRole({ roles, children }: { roles: readonly StaffRole[]; children: ReactNode }) {
  const { state } = useSession();
  if (state.kind !== "staff") return null;
  if (!roles.includes(state.user.role)) return <ForbiddenView />;
  return <>{children}</>;
}
