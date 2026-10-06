import { cx } from "./cx";

export interface LogoProps {
  /** Dòng phụ dưới chữ, ví dụ "Quản trị". */
  tagline?: string;
  className?: string;
  /** Chỉ hiện biểu tượng (header mobile hẹp). */
  markOnly?: boolean;
}

/**
 * Logo tạm (chờ PO gửi logo chính thức): viên vitamin nửa mực tím nửa cam + chữ VitaminVui.
 * Đặt trong thẻ link về trang chủ; chữ "VitaminVui" là tên truy cập được.
 */
export function Logo({ tagline, className, markOnly = false }: LogoProps) {
  return (
    <span className={cx("inline-flex items-center gap-2", className)}>
      <svg width="34" height="22" viewBox="0 0 34 22" aria-hidden="true" className="shrink-0">
        <rect x="1" y="1" width="32" height="20" rx="10" className="fill-accent" />
        <path d="M11 1h6v20h-6C5.477 21 1 16.523 1 11S5.477 1 11 1Z" className="fill-primary" />
        <path d="m7.5 11 2.5 2.5 4.5-5" fill="none" className="stroke-on-primary" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
      <span className={cx("flex flex-col leading-none", markOnly && "sr-only")}>
        <span className="text-lg font-extrabold tracking-heading text-ink">VitaminVui</span>
        {tagline ? <span className="mt-0.5 text-xs font-medium text-ink-soft">{tagline}</span> : null}
      </span>
    </span>
  );
}
