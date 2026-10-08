import type { StaffUser } from "@/lib/auth/types";

/** Chỉ Admin (gate `manage-system`) quản lý tài khoản staff. Ưu tiên `permissions.manage_system`. Chỉ để ẩn/hiện UI (API kiểm thật). */
export function canManageStaff(user: Pick<StaffUser, "role" | "permissions">): boolean {
  return user.permissions?.manage_system ?? user.role === "admin";
}
