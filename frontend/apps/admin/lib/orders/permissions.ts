import type { StaffUser } from "@/lib/auth/types";

/** Admin/Quản lý trang xem đơn hàng; giáo viên thì không. Ưu tiên `permissions.view_orders`. Chỉ để ẩn/hiện UI (API kiểm thật). */
export function canViewOrders(user: Pick<StaffUser, "role" | "permissions">): boolean {
  return user.permissions?.view_orders ?? user.role !== "giao_vien";
}
