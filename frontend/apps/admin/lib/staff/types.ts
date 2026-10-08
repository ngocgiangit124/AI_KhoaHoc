import type { PaginatedResponse } from "@vitaminvui/api-client";
import type { StaffRole } from "@/lib/auth/types";

export type StaffStatus = "active" | "locked";
export const STAFF_STATUSES: readonly StaffStatus[] = ["active", "locked"];
export const STAFF_STATUS_LABELS: Record<StaffStatus, string> = { active: "Đang hoạt động", locked: "Đã khóa" };

export const PER_PAGE_OPTIONS = [25, 50] as const;
export type PerPage = (typeof PER_PAGE_OPTIONS)[number];

/** `StaffAccount` của api-contract (T33, phẳng; không bao giờ có mật khẩu). */
export interface StaffAccount {
  id: number;
  name: string;
  email: string;
  role: StaffRole;
  status: StaffStatus;
  must_change_password: boolean;
  last_login_at: string | null;
  password_changed_at: string | null;
  created_at: string;
  is_self: boolean;
}

/** Tạo / đặt lại mật khẩu: mật khẩu hệ thống sinh, chỉ trả một lần. */
export type StaffWithPassword = StaffAccount & { initial_password: string };
/** Đổi vai trò: khóa bị gỡ giáo viên khi hạ quyền một giáo viên. */
export type StaffRoleResult = StaffAccount & { released_course_ids: number[] };

export type StaffPage = PaginatedResponse<StaffAccount>;

/** Bộ lọc trên URL của `/quan-tri/tai-khoan`. */
export interface StaffQuery {
  q: string;
  role: StaffRole | "";
  status: StaffStatus | "";
  page: number;
  perPage: PerPage;
}
