import { cx } from "./cx";
import { IconChevronRight } from "./icons";
import { UiLink } from "./Link";

export interface BreadcrumbItem {
  label: string;
  href?: string;
}

/** Đường dẫn cấp. Mục cuối là trang hiện tại (aria-current). Mobile: chỉ hiện mục cha gần nhất (dạng "‹ quay lại"). */
export function Breadcrumb({ items, className }: { items: BreadcrumbItem[]; className?: string }) {
  return (
    <nav aria-label="Đường dẫn" className={cx("text-sm", className)}>
      <ol className="flex flex-wrap items-center gap-1 text-ink-soft">
        {items.map((item, i) => {
          const last = i === items.length - 1;
          const parentOfLast = i === items.length - 2;
          return (
            <li key={`${item.label}-${i}`} className={cx("items-center gap-1", parentOfLast ? "flex" : "hidden sm:flex")}>
              {item.href && !last ? (
                <UiLink href={item.href} className="focus-ring rounded font-medium text-ink-soft underline-offset-4 hover:text-primary hover:underline">
                  {item.label}
                </UiLink>
              ) : (
                <span aria-current={last ? "page" : undefined} className="font-medium text-ink">
                  {item.label}
                </span>
              )}
              {!last ? (
                <IconChevronRight size={16} className={cx("text-ink-soft", parentOfLast && "hidden sm:block")} />
              ) : null}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
