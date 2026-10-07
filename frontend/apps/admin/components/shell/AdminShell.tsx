"use client";

import type { ReactNode } from "react";
import { usePathname } from "next/navigation";
import {
  AdminFrame,
  IconBookOpen,
  IconFileText,
  IconHome,
  IconReceipt,
  IconShapes,
  IconShieldCheck,
  IconTicket,
  IconUser,
  IconUserCheck,
  IconUsers,
  type AdminNavGroup,
  type AdminNavItem,
} from "@vitaminvui/ui/v2";
import { isNavActive, NAV_GROUP_LABELS, navForUser, type NavGroupKey, type NavItem } from "@/lib/nav";
import { STAFF_ROLE_LABELS, type StaffUser } from "@/lib/auth/types";
import { LogoutButton } from "./LogoutButton";

const ICONS: Record<NavItem["icon"], ReactNode> = {
  home: <IconHome size={18} />,
  book: <IconBookOpen size={18} />,
  shapes: <IconShapes size={18} />,
  ticket: <IconTicket size={18} />,
  receipt: <IconReceipt size={18} />,
  shield: <IconShieldCheck size={18} />,
  file: <IconFileText size={18} />,
  users: <IconUsers size={18} />,
  user: <IconUser size={18} />,
  "user-check": <IconUserCheck size={18} />,
};

const GROUP_ORDER: readonly NavGroupKey[] = ["content", "sales", "system"];

/** Menu theo vai trò (`navForUser`) → nhóm của `AdminFrame`; mục chưa có màn hiện mờ kèm nhãn, không phải link (tránh 404). */
export function navGroups(user: Pick<StaffUser, "role" | "permissions">, pathname: string): AdminNavGroup[] {
  const items = navForUser(user);
  return GROUP_ORDER.flatMap((key) => {
    const inGroup = items.filter((i) => i.group === key);
    if (inGroup.length === 0) return [];
    const mapped: AdminNavItem[] = inGroup.map((i) => ({
      href: i.href,
      label: i.label,
      icon: ICONS[i.icon],
      current: i.ready && isNavActive(i.href, pathname),
      ...(i.ready ? {} : { disabledNote: i.pending?.note ?? "Sắp có", disabledReason: i.pending?.reason }),
    }));
    return [{ label: NAV_GROUP_LABELS[key], items: mapped }];
  });
}

/**
 * Khung quản trị (design v2 §14): dựng từ `AdminFrame` (sidebar 256px ≥ lg, topbar + ngăn kéo ở mobile) với dữ liệu
 * phiên thật. Menu theo vai trò/quyền của `/admin/auth/me`; chỉ để trải nghiệm — quyền thật do API kiểm.
 */
export function AdminShell({ user, children }: { user: StaffUser; children: ReactNode }) {
  const pathname = usePathname();
  return (
    <>
      <a
        href="#noi-dung"
        className="focus-ring fixed left-2 top-2 z-[300] -translate-y-20 rounded-control bg-surface px-3 py-2 text-sm font-semibold text-primary focus:translate-y-0"
      >
        Bỏ qua tới nội dung
      </a>
      <AdminFrame
        homeHref="/quan-tri"
        navLabel="Menu quản trị"
        userNameTestId="staff-name"
        groups={navGroups(user, pathname)}
        user={{ name: user.name, email: user.email ?? "", roleLabel: STAFF_ROLE_LABELS[user.role] }}
        logoutSlot={<LogoutButton />}
      >
        {children}
      </AdminFrame>
    </>
  );
}
