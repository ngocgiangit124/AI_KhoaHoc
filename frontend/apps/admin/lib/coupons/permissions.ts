import type { StaffUser } from "@/lib/auth/types";

/** Admin/Quản lý trang quản lý mã giảm giá; giáo viên thì không. Ưu tiên `permissions.manage_coupons`. Chỉ để ẩn/hiện UI (API kiểm thật). */
export function canManageCoupons(user: Pick<StaffUser, "role" | "permissions">): boolean {
  return user.permissions?.manage_coupons ?? user.role !== "giao_vien";
}
