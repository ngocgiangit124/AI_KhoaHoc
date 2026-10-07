import type { StaffUser } from "@/lib/auth/types";

/** Staff (admin/quản lý trang) so với giáo viên. Ưu tiên `permissions.manage_all_courses`; thiếu thì theo vai trò. Chỉ để ẩn/hiện UI. */
export function isCourseStaff(user: Pick<StaffUser, "role" | "permissions">): boolean {
  return user.permissions?.manage_all_courses ?? user.role !== "giao_vien";
}
