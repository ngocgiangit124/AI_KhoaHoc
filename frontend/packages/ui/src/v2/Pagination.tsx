import { cx } from "./cx";
import { IconChevronLeft, IconChevronRight } from "./icons";
import { UiLink } from "./Link";

export interface PaginationProps {
  currentPage: number;
  lastPage: number;
  /** Tạo URL cho trang n (giữ nguyên bộ lọc trên URL). */
  hrefFor: (page: number) => string;
  className?: string;
}

function pagesToShow(current: number, last: number): Array<number | "gap"> {
  const set = new Set([1, last, current, current - 1, current + 1]);
  const pages = [...set].filter((p) => p >= 1 && p <= last).sort((a, b) => a - b);
  const out: Array<number | "gap"> = [];
  pages.forEach((p, i) => {
    if (i > 0 && p - (pages[i - 1] ?? p) > 1) out.push("gap");
    out.push(p);
  });
  return out;
}

/** Phân trang theo liên kết (length-aware). Ẩn khi chỉ có 1 trang. Mobile chỉ hiện Trước/Sau + "Trang x/y". */
export function Pagination({ currentPage, lastPage, hrefFor, className }: PaginationProps) {
  if (lastPage <= 1) return null;
  const item = "focus-ring inline-flex h-11 min-w-11 items-center justify-center rounded-control px-3 text-base font-semibold";
  return (
    <nav aria-label="Phân trang" className={cx("flex items-center justify-between gap-2 sm:justify-center", className)}>
      {currentPage > 1 ? (
        <UiLink href={hrefFor(currentPage - 1)} className={cx(item, "gap-1 text-ink hover:bg-primary-soft hover:text-primary")}>
          <IconChevronLeft size={18} />
          Trước
        </UiLink>
      ) : (
        <span className={cx(item, "gap-1 text-ink-soft")} aria-disabled="true">
          <IconChevronLeft size={18} />
          Trước
        </span>
      )}
      <span className="num text-sm font-medium text-ink-soft sm:hidden">
        Trang {currentPage}/{lastPage}
      </span>
      <ol className="hidden items-center gap-1 sm:flex">
        {pagesToShow(currentPage, lastPage).map((p, i) =>
          p === "gap" ? (
            <li key={`gap-${i}`} className="px-1 text-ink-soft" aria-hidden="true">
              …
            </li>
          ) : (
            <li key={p}>
              <UiLink
                href={hrefFor(p)}
                aria-current={p === currentPage ? "page" : undefined}
                aria-label={`Trang ${p}`}
                className={cx(item, "num", p === currentPage ? "bg-primary text-on-primary" : "text-ink hover:bg-primary-soft hover:text-primary")}
              >
                {p}
              </UiLink>
            </li>
          ),
        )}
      </ol>
      {currentPage < lastPage ? (
        <UiLink href={hrefFor(currentPage + 1)} className={cx(item, "gap-1 text-ink hover:bg-primary-soft hover:text-primary")}>
          Sau
          <IconChevronRight size={18} />
        </UiLink>
      ) : (
        <span className={cx(item, "gap-1 text-ink-soft")} aria-disabled="true">
          Sau
          <IconChevronRight size={18} />
        </span>
      )}
    </nav>
  );
}
