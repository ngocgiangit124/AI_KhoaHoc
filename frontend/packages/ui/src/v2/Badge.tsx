import type { ReactNode } from "react";
import { cx } from "./cx";

export type BadgeTone = "neutral" | "primary" | "free" | "success" | "warning" | "danger" | "info";

export interface BadgeProps {
  tone?: BadgeTone;
  /** Chấm tròn trước chữ (nhãn trạng thái ở bảng quản trị). */
  dot?: boolean;
  icon?: ReactNode;
  size?: "sm" | "md";
  className?: string;
  children: ReactNode;
}

const TONES: Record<BadgeTone, string> = {
  neutral: "bg-sunken text-ink-soft",
  primary: "bg-primary-soft text-primary",
  free: "bg-accent text-on-accent",
  success: "bg-success-soft text-success",
  warning: "bg-warning-soft text-warning",
  danger: "bg-danger-soft text-danger",
  info: "bg-info-soft text-info",
};

/**
 * Nhãn ngắn. Chữ luôn đủ nghĩa khi bỏ màu (ví dụ "Đã hoàn thành", không chỉ chấm xanh).
 * Không viết HOA toàn bộ; không xuống dòng.
 */
export function Badge({ tone = "neutral", dot = false, icon, size = "md", className, children }: BadgeProps) {
  return (
    <span
      className={cx(
        "inline-flex max-w-full items-center gap-1.5 whitespace-nowrap rounded-full font-semibold",
        size === "md" ? "px-2.5 py-1 text-sm" : "px-2 py-0.5 text-xs",
        TONES[tone],
        className,
      )}
    >
      {dot ? <span className="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true" /> : null}
      {icon}
      <span className="truncate">{children}</span>
    </span>
  );
}
