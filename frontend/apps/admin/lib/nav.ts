import { safeRedirect } from "@vitaminvui/api-client";
import type { StaffPermissions, StaffRole, StaffUser } from "@/lib/auth/types";

export interface NavItem {
  href: string;
  label: string;
  /** Vai trò thấy mục này (ma trận quyền ADR-004 §3; quyền thật do API kiểm). */
  roles: readonly StaffRole[];
  /** Quyền UI từ `/admin/auth/me`; có thì ưu tiên hơn `roles`. */
  permission?: keyof StaffPermissions;
  /** Màn đã có chưa. Chưa có → hiện mờ "Sắp có", không phải link (tránh 404). Bật khi FAx xong. */
  ready: boolean;
}

const ALL: readonly StaffRole[] = ["admin", "quan_ly_trang", "giao_vien"];
const STAFF: readonly StaffRole[] = ["admin", "quan_ly_trang"];
const ADMIN: readonly StaffRole[] = ["admin"];

export const DEFAULT_LANDING = "/quan-tri";

/**
 * Menu theo vai trò: ưu tiên `permissions` của `/admin/auth/me`, thiếu thì map theo `role` (ma trận ADR-004 §3).
 */
export const NAV_ITEMS: readonly NavItem[] = [
  { href: "/quan-tri", label: "Tổng quan", roles: ALL, ready: true },
  { href: "/quan-tri/khoa-hoc", label: "Khóa học", roles: ALL, ready: false },
  { href: "/quan-tri/chuyen-de", permission: "manage_subjects", label: "Chuyên đề", roles: STAFF, ready: true },
  { href: "/quan-tri/ma-giam-gia", permission: "manage_coupons", label: "Mã giảm giá", roles: STAFF, ready: false },
  { href: "/quan-tri/don-hang", permission: "view_orders", label: "Đơn hàng", roles: STAFF, ready: false },
  { href: "/quan-tri/tai-khoan", permission: "manage_system", label: "Tài khoản staff", roles: ADMIN, ready: false },
  { href: "/quan-tri/nhat-ky", permission: "manage_system", label: "Nhật ký thao tác", roles: ADMIN, ready: false },
];

const AUTH_PATH_RE = /^\/(dang-nhap|xac-thuc-mfa|doi-mat-khau)(?:[/?#]|$)/;

/** `safeRedirect` + không cho đích là trang auth (tránh vòng lặp đăng nhập → đăng nhập). */
export function safeNext(next: string | null | undefined, fallback: string = DEFAULT_LANDING): string {
  const target = safeRedirect(next, fallback);
  return AUTH_PATH_RE.test(target) ? fallback : target;
}

export function navForUser(user: Pick<StaffUser, "role" | "permissions">): NavItem[] {
  return NAV_ITEMS.filter((item) => {
    const granted = item.permission ? user.permissions?.[item.permission] : undefined;
    if (typeof granted === "boolean") return granted;
    return item.roles.includes(user.role);
  });
}

export function isNavActive(href: string, pathname: string): boolean {
  if (href === "/quan-tri") return pathname === "/quan-tri";
  return pathname === href || pathname.startsWith(`${href}/`);
}
