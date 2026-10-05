"use client";

import { useState, type ReactNode } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { Badge } from "@vitaminvui/ui";
import { isNavActive, navForUser } from "@/lib/nav";
import { STAFF_ROLE_LABELS, type StaffUser } from "@/lib/auth/types";
import { LogoutButton } from "./LogoutButton";

function NavList({ user, onNavigate }: { user: StaffUser; onNavigate?: () => void }) {
  const pathname = usePathname();
  return (
    <nav aria-label="Menu quản trị">
      <ul className="space-y-1 text-sm">
        {navForUser(user).map((item) => {
          const active = isNavActive(item.href, pathname);
          if (!item.ready) {
            return (
              <li key={item.href}>
                <span
                  aria-disabled="true"
                  className="flex min-h-11 items-center justify-between rounded-lg px-3 text-gray-500"
                >
                  {item.label}
                  <span className="text-xs">Sắp có</span>
                </span>
              </li>
            );
          }
          return (
            <li key={item.href}>
              <Link
                href={item.href}
                onClick={onNavigate}
                aria-current={active ? "page" : undefined}
                className={`flex min-h-11 items-center rounded-lg px-3 ${
                  active ? "bg-indigo-50 font-semibold text-indigo-700" : "text-gray-700 hover:bg-gray-50"
                }`}
              >
                {item.label}
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}

/** Khung quản trị: sidebar (menu theo vai trò) + thanh trên có người dùng/đăng xuất. Responsive từ 375px. */
export function AdminShell({ user, children }: { user: StaffUser; children: ReactNode }) {
  const [open, setOpen] = useState(false);

  return (
    <div className="flex min-h-full flex-1">
      <aside className="hidden w-60 shrink-0 border-r border-gray-200 bg-white p-4 lg:block">
        <p className="mb-6 px-2 text-lg font-bold text-indigo-600">Quản trị VitaminVui</p>
        <NavList user={user} />
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 bg-white px-4 py-2">
          <button
            type="button"
            className="inline-flex h-11 items-center rounded-lg border border-gray-300 px-3 text-sm font-medium lg:hidden"
            aria-expanded={open}
            aria-controls="admin-mobile-nav"
            onClick={() => setOpen((v) => !v)}
          >
            Menu
          </button>
          <div className="ml-auto flex items-center gap-3">
            <span className="text-sm text-gray-900" data-testid="staff-name">
              {user.name}
            </span>
            <Badge variant="info">{STAFF_ROLE_LABELS[user.role]}</Badge>
            <LogoutButton />
          </div>
        </header>

        {open ? (
          <div id="admin-mobile-nav" className="border-b border-gray-200 bg-white p-3 lg:hidden">
            <NavList user={user} onNavigate={() => setOpen(false)} />
          </div>
        ) : null}

        <main className="flex-1 p-4 lg:p-6">{children}</main>
      </div>
    </div>
  );
}
