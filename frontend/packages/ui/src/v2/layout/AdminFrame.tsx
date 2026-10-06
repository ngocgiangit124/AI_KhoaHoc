import type { ReactNode } from "react";
import { Avatar } from "../Avatar";
import { Badge } from "../Badge";
import { cx } from "../cx";
import { IconLogOut } from "../icons";
import { UiLink } from "../Link";
import { Logo } from "../Logo";
import { NavDrawer } from "./NavDrawer";

export interface AdminNavItem {
  href: string;
  label: string;
  icon: ReactNode;
  current?: boolean;
  /** Chưa mở (ví dụ Đơn hàng — thanh toán ở V2): hiện mờ kèm nhãn, không bấm được. */
  disabledNote?: string;
  /** Câu giải thích hiện ngay dưới mục bị khoá (không dùng tooltip). */
  disabledReason?: string;
  /** Số việc chờ (ví dụ yêu cầu duyệt). */
  count?: number;
}

export interface AdminNavGroup {
  label: string;
  items: AdminNavItem[];
}

export interface AdminFrameProps {
  homeHref: string;
  groups: AdminNavGroup[];
  user: { name: string; email: string; roleLabel: string };
  logoutSlot?: ReactNode;
  children: ReactNode;
  /** Dải nhỏ phía trên (môi trường, cảnh báo phiên...). */
  banner?: ReactNode;
}

function NavList({ groups }: { groups: AdminNavGroup[] }) {
  return (
    <div className="flex flex-col gap-5">
      {groups.map((group) => (
        <div key={group.label} className="flex flex-col gap-1">
          <p className="px-3 pb-1 text-sm font-semibold text-ink-soft">{group.label}</p>
          <ul className="flex flex-col gap-0.5">
            {group.items.map((item) => (
              <li key={item.href}>
                {item.disabledNote ? (
                  <span aria-disabled="true" className="flex min-h-10 cursor-not-allowed items-start gap-3 rounded-control px-3 py-2 text-sm font-medium text-ink-soft">
                    <span className="mt-0.5">{item.icon}</span>
                    <span className="flex flex-1 flex-col">
                      <span>{item.label}</span>
                      {item.disabledReason ? <span className="text-xs font-normal">{item.disabledReason}</span> : null}
                    </span>
                    <Badge size="sm">{item.disabledNote}</Badge>
                  </span>
                ) : (
                  <UiLink
                    href={item.href}
                    aria-current={item.current ? "page" : undefined}
                    className={cx(
                      "focus-ring relative flex min-h-10 items-center gap-3 rounded-control px-3 text-sm font-semibold transition-colors duration-150",
                      item.current ? "bg-primary-soft text-primary" : "text-ink hover:bg-sunken",
                    )}
                  >
                    {item.current ? <span aria-hidden="true" className="absolute inset-y-2 left-0 w-0.75 rounded-r-full bg-primary" /> : null}
                    {item.icon}
                    <span className="flex-1">{item.label}</span>
                    {item.count ? (
                      <span className="num rounded-full bg-accent px-2 text-xs font-extrabold text-on-accent" aria-label={`${item.count} việc chờ`}>
                        {item.count}
                      </span>
                    ) : null}
                  </UiLink>
                )}
              </li>
            ))}
          </ul>
        </div>
      ))}
    </div>
  );
}

/**
 * Khung trang quản trị: sidebar 256px cố định (≥ lg), topbar dính; mobile/tablet: topbar + ngăn kéo menu.
 * Cùng token với web học sinh nhưng mật độ cao hơn (chữ 14px trong bảng/menu, nút 36px).
 * Menu chỉ chứa mục vai trò được phép (ẩn hẳn, không chỉ khoá) — trừ mục "chưa mở" có nhãn giải thích.
 */
export function AdminFrame({ homeHref, groups, user, logoutSlot, banner, children }: AdminFrameProps) {
  const userBlock = (
    <div className="flex items-center gap-3">
      <Avatar name={user.name} size="sm" />
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-semibold text-ink">{user.name}</p>
        <p className="truncate text-xs text-ink-soft">{user.roleLabel}</p>
      </div>
    </div>
  );
  const logout = logoutSlot ?? (
    <button type="button" className="focus-ring inline-flex min-h-10 items-center gap-2 rounded-control px-3 text-sm font-semibold text-ink hover:bg-sunken">
      <IconLogOut size={18} />
      Đăng xuất
    </button>
  );

  return (
    <div className="flex min-h-screen bg-paper">
      <aside className="sticky top-0 hidden h-screen w-64 shrink-0 flex-col border-r border-line bg-surface lg:flex">
        <div className="flex h-16 items-center border-b border-line px-4">
          <UiLink href={homeHref} className="focus-ring rounded-control p-1" aria-label="VitaminVui Quản trị — Trang đầu">
            <Logo tagline="Quản trị" />
          </UiLink>
        </div>
        <nav aria-label="Quản trị" className="flex-1 overflow-y-auto px-3 py-4">
          <NavList groups={groups} />
        </nav>
        <div className="flex flex-col gap-2 border-t border-line p-3">
          {userBlock}
          {logout}
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-20 flex h-14 items-center gap-2 border-b border-line bg-surface px-2 lg:hidden">
          <NavDrawer title="Quản trị">
            <div className="mb-4 rounded-card bg-sunken p-3">{userBlock}</div>
            <NavList groups={groups} />
            <div className="mt-4 border-t border-line pt-3">{logout}</div>
          </NavDrawer>
          <UiLink href={homeHref} className="focus-ring rounded-control p-1" aria-label="VitaminVui Quản trị — Trang đầu">
            <Logo tagline="Quản trị" />
          </UiLink>
          <span className="ml-auto pr-2">
            <Badge tone="primary" size="sm">
              {user.roleLabel}
            </Badge>
          </span>
        </header>
        {banner}
        <main id="noi-dung" className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 lg:px-8">
          {children}
        </main>
      </div>
    </div>
  );
}
