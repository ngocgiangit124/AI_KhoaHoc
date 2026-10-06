import { cx } from "../cx";
import { UiLink } from "../Link";
import type { NavItem } from "./SiteHeader";

/**
 * Thanh điều hướng đáy (mobile, đã đăng nhập): tối đa 4–5 mục, icon + chữ, mục hiện tại tô `primary`
 * và có vạch trên. Ẩn ở trang học/làm bài để không xao nhãng. Trang dùng nó cần `pb-20 md:pb-0`.
 */
export function BottomNav({ items }: { items: Array<Required<Pick<NavItem, "icon">> & NavItem> }) {
  return (
    <nav
      aria-label="Điều hướng nhanh"
      className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface pb-[env(safe-area-inset-bottom)] md:hidden"
    >
      <ul className="mx-auto grid max-w-md" style={{ gridTemplateColumns: `repeat(${items.length}, minmax(0, 1fr))` }}>
        {items.map((item) => (
          <li key={item.href}>
            <UiLink
              href={item.href}
              aria-current={item.current ? "page" : undefined}
              className={cx(
                "focus-ring relative flex h-16 flex-col items-center justify-center gap-1 text-xs font-semibold",
                item.current ? "text-primary" : "text-ink-soft hover:text-ink",
              )}
            >
              {item.current ? <span aria-hidden="true" className="absolute inset-x-6 top-0 h-0.75 rounded-b-full bg-primary" /> : null}
              {item.icon}
              {item.label}
            </UiLink>
          </li>
        ))}
      </ul>
    </nav>
  );
}
