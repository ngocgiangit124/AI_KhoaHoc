import type { ReactNode } from "react";
import { ButtonLink } from "../Button";
import { cx } from "../cx";
import { IconCart, IconSearch } from "../icons";
import { UiLink } from "../Link";
import { Logo } from "../Logo";
import { Avatar } from "../Avatar";
import { NavDrawer } from "./NavDrawer";

export interface NavItem {
  href: string;
  label: string;
  current?: boolean;
  icon?: ReactNode;
}

export interface SiteHeaderProps {
  homeHref: string;
  nav: NavItem[];
  /** null = khách. */
  viewer: { name: string; accountHref: string } | null;
  loginHref: string;
  registerHref: string;
  /** Form tìm kiếm GET tới danh mục (`?q=`). */
  searchAction: string;
  searchDefault?: string;
  /**
   * Giỏ hàng chỉ hiện khi `paid_checkout_enabled=true` (config/public). Khi thanh toán tạm khoá, ẩn
   * hẳn để học sinh không đi vào ngõ cụt (quyết định chờ PO, xem design-system-v2 §12.2).
   */
  cart?: { href: string; count: number } | null;
  /** Ẩn ô tìm kiếm (trang đăng nhập/đăng ký). */
  minimal?: boolean;
}

/**
 * Header công khai/học sinh: logo · điều hướng · tìm kiếm · tài khoản. Dính trên cùng, nền `surface`.
 * Mobile: logo + tìm kiếm + nút menu (ngăn kéo); thao tác chính ở bottom-nav khi đã đăng nhập.
 */
export function SiteHeader({ homeHref, nav, viewer, loginHref, registerHref, searchAction, searchDefault, cart, minimal = false }: SiteHeaderProps) {
  return (
    <header className="sticky top-0 z-30 border-b border-line bg-surface">
      <div className="mx-auto flex h-16 max-w-6xl items-center gap-3 px-4 sm:px-6">
        <UiLink href={homeHref} className="focus-ring -ml-1 rounded-control p-1" aria-label="VitaminVui — Trang chủ">
          <Logo />
        </UiLink>

        {!minimal ? (
          <nav aria-label="Chính" className="ml-4 hidden items-center gap-1 md:flex">
            {nav.map((item) => (
              <UiLink
                key={item.href}
                href={item.href}
                aria-current={item.current ? "page" : undefined}
                className={cx(
                  "focus-ring rounded-control px-3 py-2 text-base font-semibold transition-colors duration-150",
                  item.current ? "bg-primary-soft text-primary" : "text-ink hover:text-primary",
                )}
              >
                {item.label}
              </UiLink>
            ))}
          </nav>
        ) : null}

        <div className="ml-auto flex items-center gap-1 sm:gap-2">
          {!minimal ? (
            <form action={searchAction} role="search" className="hidden lg:block">
              <label htmlFor="site-search" className="sr-only">
                Tìm khóa học
              </label>
              <div className="relative">
                <IconSearch size={18} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-soft" />
                <input
                  id="site-search"
                  name="q"
                  type="search"
                  defaultValue={searchDefault}
                  placeholder="Ví dụ: hình học lớp 9"
                  className="h-11 w-64 rounded-control border border-line-strong bg-paper pl-10 pr-3 text-base text-ink placeholder:text-ink-soft focus:border-primary focus:outline-2 focus:outline-primary/30"
                />
              </div>
            </form>
          ) : null}
          {!minimal ? (
            <UiLink
              href={searchAction}
              aria-label="Tìm khóa học"
              className="focus-ring inline-flex size-11 items-center justify-center rounded-control text-ink hover:bg-primary-soft hover:text-primary lg:hidden"
            >
              <IconSearch />
            </UiLink>
          ) : null}
          {cart ? (
            <UiLink
              href={cart.href}
              aria-label={`Giỏ hàng, ${cart.count} khóa`}
              className="focus-ring relative inline-flex size-11 items-center justify-center rounded-control text-ink hover:bg-primary-soft hover:text-primary"
            >
              <IconCart />
              {cart.count > 0 ? (
                <span className="num absolute right-1 top-1 inline-flex min-w-5 items-center justify-center rounded-full bg-accent px-1 text-xs font-extrabold text-on-accent">
                  {cart.count}
                </span>
              ) : null}
            </UiLink>
          ) : null}
          {viewer ? (
            <UiLink
              href={viewer.accountHref}
              className="focus-ring hidden items-center gap-2 rounded-control py-1 pl-1 pr-3 text-base font-semibold text-ink hover:bg-primary-soft md:inline-flex"
            >
              <Avatar name={viewer.name} size="sm" />
              <span className="max-w-32 truncate">{viewer.name}</span>
            </UiLink>
          ) : (
            <div className="hidden items-center gap-2 sm:flex">
              <ButtonLink href={loginHref} variant="ghost">
                Đăng nhập
              </ButtonLink>
              <ButtonLink href={registerHref}>Đăng ký</ButtonLink>
            </div>
          )}
          {!minimal ? (
            <div className="md:hidden">
              <NavDrawer title="Menu">
                <nav aria-label="Chính (mobile)" className="flex flex-col gap-1">
                  {nav.map((item) => (
                    <UiLink
                      key={item.href}
                      href={item.href}
                      aria-current={item.current ? "page" : undefined}
                      className={cx(
                        "focus-ring flex min-h-12 items-center gap-3 rounded-control px-3 text-base font-semibold",
                        item.current ? "bg-primary-soft text-primary" : "text-ink hover:bg-sunken",
                      )}
                    >
                      {item.icon}
                      {item.label}
                    </UiLink>
                  ))}
                </nav>
                {viewer ? null : (
                  <div className="mt-4 grid grid-cols-2 gap-2 border-t border-line pt-4">
                    <ButtonLink href={loginHref} variant="secondary">
                      Đăng nhập
                    </ButtonLink>
                    <ButtonLink href={registerHref}>Đăng ký</ButtonLink>
                  </div>
                )}
              </NavDrawer>
            </div>
          ) : null}
        </div>
      </div>
    </header>
  );
}
