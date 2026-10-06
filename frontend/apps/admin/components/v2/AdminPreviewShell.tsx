import Link from "next/link";
import type { ReactNode } from "react";
import {
  AdminFrame,
  IconBookOpen,
  IconFileText,
  IconReceipt,
  IconShapes,
  IconShieldCheck,
  IconTicket,
  IconUser,
  IconUserCheck,
  IconUsers,
  cx,
  type AdminNavGroup,
} from "@vitaminvui/ui/v2";
import { PENDING_REQUESTS, ROLE_LABEL, STAFF, type StaffRole, type StaffUser } from "@/lib/mock/v2/data";

export type AdminSection = "courses" | "subjects" | "requests" | "teachers" | "coupons" | "orders" | "staff" | "audit" | "profile";

/** Đọc `?vai-tro=` của bản xem trước (mặc định admin). */
export function roleFrom(v: string | string[] | undefined): StaffRole {
  const r = Array.isArray(v) ? v[0] : v;
  return r === "quan_ly_trang" || r === "giao_vien" ? r : "admin";
}

/**
 * Menu theo quyền UI của `/admin/auth/me` (`permissions`), ẩn hẳn mục không có quyền.
 * Đơn hàng: thanh toán chuyển V2 → hiện mờ "V2" cho người có quyền xem đơn.
 */
export function navFor(user: StaffUser, current: AdminSection, q: string): AdminNavGroup[] {
  const p = user.permissions;
  const href = (path: string) => `/v2/quan-tri/${path}${q}`;
  const groups: AdminNavGroup[] = [
    {
      label: "Nội dung",
      items: [
        { href: href("khoa-hoc"), label: user.role === "giao_vien" ? "Khóa học của tôi" : "Khóa học", icon: <IconBookOpen size={18} />, current: current === "courses" },
        // Giáo viên: tạm cho xem chỉ-đọc (board: chờ PO).
        { href: href("chuyen-de"), label: "Chuyên đề", icon: <IconShapes size={18} />, current: current === "subjects" },
        { href: href("duyet-dang-ky"), label: "Duyệt đăng ký", icon: <IconUserCheck size={18} />, current: current === "requests", count: PENDING_REQUESTS },
        // US-020: Admin/QLT chọn giáo viên hiện ở trang chủ.
        ...(p.manage_all_courses ? [{ href: href("giao-vien"), label: "Giáo viên trang chủ", icon: <IconUsers size={18} />, current: current === "teachers" }] : []),
      ],
    },
  ];
  // US-020: chỉ giáo viên có hồ sơ công khai (BR12).
  if (user.role === "giao_vien") {
    groups.push({
      label: "Tài khoản",
      items: [{ href: href("ho-so"), label: "Hồ sơ của tôi", icon: <IconUser size={18} />, current: current === "profile" }],
    });
  }
  if (p.manage_coupons || p.view_orders) {
    groups.push({
      label: "Bán hàng",
      items: [
        ...(p.manage_coupons ? [{ href: href("ma-giam-gia"), label: "Mã giảm giá", icon: <IconTicket size={18} />, current: current === "coupons" }] : []),
        ...(p.view_orders ? [{ href: href("don-hang"), label: "Đơn hàng", icon: <IconReceipt size={18} />, disabledNote: "V2", disabledReason: "Mở khi bật thanh toán trực tuyến" }] : []),
      ],
    });
  }
  if (p.manage_system) {
    groups.push({
      label: "Hệ thống",
      items: [
        { href: href("tai-khoan"), label: "Tài khoản staff", icon: <IconShieldCheck size={18} />, current: current === "staff" },
        { href: href("nhat-ky"), label: "Nhật ký thao tác", icon: <IconFileText size={18} />, current: current === "audit" },
      ],
    });
  }
  return groups;
}

/** Khung bản xem trước quản trị: dải chọn vai trò/trạng thái + AdminFrame. */
export function AdminPreviewShell({
  role,
  current,
  basePath,
  extraQuery = "",
  states = [],
  state,
  roles = ["admin", "quan_ly_trang", "giao_vien"],
  children,
}: {
  /** Vai trò được chọn ở dải xem trước (màn chỉ dành cho một số vai trò). */
  roles?: StaffRole[];
  role: StaffRole;
  current: AdminSection;
  basePath: string;
  extraQuery?: string;
  states?: Array<{ key?: string; label: string }>;
  state?: string;
  children: ReactNode;
}) {
  const user = STAFF[role];
  const roleQ = role === "admin" ? "" : `?vai-tro=${role}`;
  const join = (params: string[]) => {
    const s = params.filter(Boolean).join("&");
    return s ? `${basePath}?${s}` : basePath;
  };
  const pill = (active: boolean) =>
    cx(
      "focus-ring inline-flex min-h-8 items-center rounded-full border px-2.5 font-medium",
      active ? "border-primary bg-primary text-on-primary" : "border-line-strong bg-surface text-ink hover:border-primary",
    );
  const banner = (
    <div className="border-b border-dashed border-line-strong bg-sunken text-sm">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2 sm:px-6 lg:px-8">
        <span className="font-semibold text-ink">Xem trước v2</span>
        <nav aria-label="Vai trò" className="flex flex-wrap items-center gap-1.5">
          <span className="text-ink-soft">Vai trò:</span>
          {roles.map((r) => (
            <Link key={r} href={join([r === roles[0] ? "" : `vai-tro=${r}`, extraQuery])} aria-current={r === role ? "true" : undefined} className={pill(r === role)}>
              {ROLE_LABEL[r]}
            </Link>
          ))}
        </nav>
        {states.length ? (
          <nav aria-label="Trạng thái" className="flex flex-wrap items-center gap-1.5">
            <span className="text-ink-soft">Trạng thái:</span>
            {states.map((s) => (
              <Link
                key={s.label}
                href={join([role === roles[0] ? "" : `vai-tro=${role}`, extraQuery, s.key ? `trang-thai=${s.key}` : ""])}
                aria-current={s.key === state ? "true" : undefined}
                className={pill(s.key === state)}
              >
                {s.label}
              </Link>
            ))}
          </nav>
        ) : null}
      </div>
    </div>
  );
  return (
    <AdminFrame homeHref={`/v2/quan-tri/khoa-hoc${roleQ}`} groups={navFor(user, current, roleQ)} user={{ name: user.name, email: user.email, roleLabel: ROLE_LABEL[role] }} banner={banner}>
      {children}
    </AdminFrame>
  );
}
