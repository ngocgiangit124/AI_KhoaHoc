"use client";

import { useId, useRef, useState, type KeyboardEvent, type ReactNode } from "react";
import { cx } from "./cx";
import { UiLink } from "./Link";

export interface TabItem {
  id: string;
  label: ReactNode;
  /** Số đếm nhỏ sau nhãn, ví dụ số bài. */
  count?: number;
  content: ReactNode;
}

export interface TabsProps {
  items: TabItem[];
  defaultTab?: string;
  /** Tên cho trình đọc màn hình. */
  label: string;
  className?: string;
  /** Kéo dãn tab chia đều chiều ngang (mobile). */
  fitted?: boolean;
}

const TAB =
  "focus-ring relative inline-flex min-h-11 items-center gap-2 whitespace-nowrap px-1 text-base font-semibold transition-colors duration-150";

/**
 * Tab theo mẫu WAI-ARIA: mũi tên trái/phải, Home/End; chỉ tab đang chọn nằm trong thứ tự Tab.
 * Gạch chân 3px màu primary cho tab đang chọn (không chỉ đổi màu chữ).
 */
export function Tabs({ items, defaultTab, label, className, fitted = false }: TabsProps) {
  const baseId = useId();
  const [active, setActive] = useState(defaultTab ?? items[0]?.id);
  const refs = useRef<Array<HTMLButtonElement | null>>([]);

  function onKeyDown(e: KeyboardEvent<HTMLButtonElement>, index: number) {
    const last = items.length - 1;
    let next = -1;
    if (e.key === "ArrowRight") next = index === last ? 0 : index + 1;
    else if (e.key === "ArrowLeft") next = index === 0 ? last : index - 1;
    else if (e.key === "Home") next = 0;
    else if (e.key === "End") next = last;
    if (next < 0) return;
    e.preventDefault();
    const item = items[next];
    if (!item) return;
    setActive(item.id);
    refs.current[next]?.focus();
  }

  return (
    <div className={className}>
      <div role="tablist" aria-label={label} className={cx("flex gap-6 overflow-x-auto border-b border-line", fitted && "gap-0")}>
        {items.map((item, i) => {
          const selected = item.id === active;
          return (
            <button
              key={item.id}
              ref={(el) => {
                refs.current[i] = el;
              }}
              id={`${baseId}-tab-${item.id}`}
              role="tab"
              type="button"
              aria-selected={selected}
              aria-controls={`${baseId}-panel-${item.id}`}
              tabIndex={selected ? 0 : -1}
              onClick={() => setActive(item.id)}
              onKeyDown={(e) => onKeyDown(e, i)}
              className={cx(TAB, fitted && "flex-1 justify-center", selected ? "text-primary" : "text-ink-soft hover:text-ink")}
            >
              {item.label}
              {item.count !== undefined ? <span className="num rounded-full bg-sunken px-2 text-sm text-ink-soft">{item.count}</span> : null}
              {selected ? <span aria-hidden="true" className="absolute inset-x-0 -bottom-px h-0.75 rounded-full bg-primary" /> : null}
            </button>
          );
        })}
      </div>
      {items.map((item) => (
        <div
          key={item.id}
          id={`${baseId}-panel-${item.id}`}
          role="tabpanel"
          aria-labelledby={`${baseId}-tab-${item.id}`}
          hidden={item.id !== active}
          tabIndex={0}
          className="focus-ring pt-4"
        >
          {item.content}
        </div>
      ))}
    </div>
  );
}

export interface LinkTabItem {
  href: string;
  label: ReactNode;
  current: boolean;
  count?: number;
}

/**
 * Tab điều hướng theo URL (mỗi tab là một trang/`?tab=`): F5 và chia sẻ link giữ đúng tab.
 * Là danh sách liên kết với `aria-current`, không phải tablist.
 */
export function LinkTabs({ items, label, className }: { items: LinkTabItem[]; label: string; className?: string }) {
  return (
    <nav aria-label={label} className={cx("flex gap-6 overflow-x-auto border-b border-line", className)}>
      {items.map((item) => (
        <UiLink
          key={item.href}
          href={item.href}
          aria-current={item.current ? "page" : undefined}
          className={cx(TAB, item.current ? "text-primary" : "text-ink-soft hover:text-ink")}
        >
          {item.label}
          {item.count !== undefined ? <span className="num rounded-full bg-sunken px-2 text-sm text-ink-soft">{item.count}</span> : null}
          {item.current ? <span aria-hidden="true" className="absolute inset-x-0 -bottom-px h-0.75 rounded-full bg-primary" /> : null}
        </UiLink>
      ))}
    </nav>
  );
}
