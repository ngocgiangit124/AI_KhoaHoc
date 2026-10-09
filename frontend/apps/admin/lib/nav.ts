import { safeRedirect } from "@vitaminvui/api-client";
import type { StaffPermissions, StaffRole, StaffUser } from "@/lib/auth/types";

/** Nhóm menu theo design v2 §14: "Nội dung / Bán hàng / Hệ thống". */
export type NavGroupKey = "content" | "sales" | "system";
export const NAV_GROUP_LABELS: Record<NavGroupKey, string> = { content: "Nội dung", sales: "Bán hàng", system: "Hệ thống" };

export interface NavItem {
  href: string;
  label: string;
  group: NavGroupKey;
  /** Tên icon (AdminShell ánh xạ sang icon v2). */
  icon: "home" | "book" | "shapes" | "ticket" | "receipt" | "shield" | "file" | "users" | "user" | "user-check";
  /** Vai trò thấy mục này (ma trận quyền ADR-004 §3; quyền thật do API kiểm). */
  roles: readonly StaffRole[];
  /** Quyền UI từ `/admin/auth/me`; có thì ưu tiên hơn `roles`. */
  permission?: keyof StaffPermissions;
  /** Màn đã có chưa. Chưa có → hiện mờ "Sắp có", không phải link (tránh 404). Bật khi FAx xong. */
  ready: boolean;
  /** Chỉ hiện khi `/admin/me/teacher-profile` cho thấy người này còn dữ liệu hồ sơ (FA11-1), ngoài điều kiện vai trò. */
  needsLegacyProfile?: boolean;
  /** Nhãn + lý do khi chưa mở (mặc định "Sắp có", không kèm lý do). Ví dụ Đơn hàng: V2 (thanh toán tạm khoá). */
  pending?: { note: string; reason?: string };
}

const ALL: readonly StaffRole[] = ["admin", "quan_ly_trang", "giao_vien"];
const STAFF: readonly StaffRole[] = ["admin", "quan_ly_trang"];
const ADMIN: readonly StaffRole[] = ["admin"];
const TEACHER: readonly StaffRole[] = ["giao_vien"];

export const DEFAULT_LANDING = "/quan-tri";

/**
 * Menu theo vai trò: ưu tiên `permissions` của `/admin/auth/me`, thiếu thì map theo `role` (ma trận ADR-004 §3).
 */
export const NAV_ITEMS: readonly NavItem[] = [
  { href: "/quan-tri", label: "Tổng quan", group: "content", icon: "home", roles: ALL, ready: true },
  { href: "/quan-tri/khoa-hoc", label: "Khóa học", group: "content", icon: "book", roles: ALL, ready: true },
  { href: "/quan-tri/chuyen-de", permission: "manage_subjects", label: "Chuyên đề", group: "content", icon: "shapes", roles: STAFF, ready: true },
  { href: "/quan-tri/duyet-dang-ky", label: "Duyệt đăng ký", group: "content", icon: "user-check", roles: ALL, ready: true },
  { href: "/quan-tri/giao-vien", label: "Giáo viên trang chủ", group: "content", icon: "users", roles: STAFF, ready: true },
  { href: "/quan-tri/ho-so", label: "Hồ sơ của tôi", group: "content", icon: "user", roles: TEACHER, ready: true },
  { href: "/quan-tri/ho-so", label: "Hồ sơ giáo viên cũ", group: "content", icon: "user", roles: STAFF, ready: true, needsLegacyProfile: true },
  { href: "/quan-tri/ma-giam-gia", permission: "manage_coupons", label: "Mã giảm giá", group: "sales", icon: "ticket", roles: STAFF, ready: true },
  {
    href: "/quan-tri/don-hang",
    permission: "view_orders",
    label: "Đơn hàng",
    group: "sales",
    icon: "receipt",
    roles: STAFF,
    ready: true,
  },
  { href: "/quan-tri/tai-khoan", permission: "manage_system", label: "Tài khoản staff", group: "system", icon: "shield", roles: ADMIN, ready: true },
  { href: "/quan-tri/nhat-ky", permission: "manage_system", label: "Nhật ký thao tác", group: "system", icon: "file", roles: ADMIN, ready: false },
];

const AUTH_PATH_RE = /^\/(dang-nhap|xac-thuc-mfa|doi-mat-khau)(?:[/?#]|$)/;

/** `safeRedirect` + không cho đích là trang auth (tránh vòng lặp đăng nhập → đăng nhập). */
export function safeNext(next: string | null | undefined, fallback: string = DEFAULT_LANDING): string {
  const target = safeRedirect(next, fallback);
  return AUTH_PATH_RE.test(target) ? fallback : target;
}

export function navForUser(user: Pick<StaffUser, "role" | "permissions">, opts: { legacyProfile?: boolean } = {}): NavItem[] {
  return NAV_ITEMS.filter((item) => {
    if (item.needsLegacyProfile && !opts.legacyProfile) return false;
    const granted = item.permission ? user.permissions?.[item.permission] : undefined;
    if (typeof granted === "boolean") return granted;
    return item.roles.includes(user.role);
  });
}

export function isNavActive(href: string, pathname: string): boolean {
  if (href === "/quan-tri") return pathname === "/quan-tri";
  return pathname === href || pathname.startsWith(`${href}/`);
}
