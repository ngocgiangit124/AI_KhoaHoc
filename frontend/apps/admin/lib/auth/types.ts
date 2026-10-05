export const STAFF_ROLES = ["admin", "quan_ly_trang", "giao_vien"] as const;
export type StaffRole = (typeof STAFF_ROLES)[number];

export const STAFF_ROLE_LABELS: Record<StaffRole, string> = {
  admin: "Admin",
  quan_ly_trang: "Quản lý trang",
  giao_vien: "Giáo viên",
};

/**
 * Người dùng quản trị từ `GET /admin/auth/me` (shape theo `StaffUserResource` của T28; api-contract §2.5
 * mới ghi "user + role + quyền UI"). `role` bắt buộc; còn lại đọc mềm.
 */
export interface StaffPermissions {
  manage_system: boolean;
  manage_subjects: boolean;
  manage_all_courses: boolean;
  manage_coupons: boolean;
  view_orders: boolean;
  export_orders: boolean;
  export_orders_with_contact: boolean;
}

export interface StaffUser {
  id: number | string | null;
  name: string;
  email: string | null;
  role: StaffRole;
  /** `permissions` từ API (chỉ để ẩn/hiện UI). Thiếu → menu rơi về map theo `role`. */
  permissions: Partial<StaffPermissions> | null;
  mustChangePassword: boolean;
  /** Từ `session` của API: idle tối đa (phút) và hạn tuyệt đối (ISO). */
  session: { idleTimeoutMinutes: number | null; expiresAt: string | null } | null;
}

export function isStaffRole(value: unknown): value is StaffRole {
  return typeof value === "string" && (STAFF_ROLES as readonly string[]).includes(value);
}
