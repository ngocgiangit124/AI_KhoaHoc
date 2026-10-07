import { createElement, type HTMLAttributes, type ReactNode } from "react";
import { cx } from "./cx";

export interface SheetProps extends HTMLAttributes<HTMLElement> {
  /** Thẻ HTML: `section` (có tiêu đề), `div`, `aside`, `article`. */
  as?: "div" | "section" | "aside" | "article";
  /** `md` (mặc định): 20px mobile / 24px từ sm. `sm`: 16px (thanh lọc, khối phụ). `none`: tự đặt. */
  padding?: "none" | "sm" | "md";
  children?: ReactNode;
}

const PAD = { none: "", sm: "p-4", md: "p-5 sm:p-6" } as const;

/**
 * "Tờ giấy trơn" đặt trên nền vở ô ly (`bg-oly-page`): nền `surface` đặc + viền `line` + bo `rounded-sheet`,
 * không bóng. Dùng cho đoạn văn dài (> 3 dòng), form, bảng, danh sách dài trên trang khách để lưới không chạy
 * sau chữ (design-system-v2 §3.1).
 */
export function Sheet({ as = "div", padding = "md", className, children, ...rest }: SheetProps) {
  return createElement(as, { ...rest, className: cx("rounded-sheet border border-line bg-surface", PAD[padding], className) }, children);
}
