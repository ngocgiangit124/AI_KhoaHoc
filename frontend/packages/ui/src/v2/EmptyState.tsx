import type { ReactNode } from "react";
import { cx } from "./cx";

export interface EmptyStateProps {
  /** Icon SVG (./icons), đặt trên một ô vở nhỏ. */
  icon?: ReactNode;
  title: string;
  description?: ReactNode;
  /** Hành động gợi ý: ButtonLink/Button. Trạng thái rỗng luôn chỉ đường đi tiếp. */
  action?: ReactNode;
  secondaryAction?: ReactNode;
  /** `page`: chiếm khối nội dung chính; `inline`: trong một thẻ/khu vực nhỏ. */
  size?: "page" | "inline";
  /** Trang lỗi/không tìm thấy dùng h1; mặc định h2. */
  headingLevel?: "h1" | "h2" | "h3";
  className?: string;
}

/** Trạng thái rỗng / không tìm thấy / lỗi tải: minh hoạ ô vở + câu nói rõ chuyện gì + việc làm tiếp. */
export function EmptyState({
  icon,
  title,
  description,
  action,
  secondaryAction,
  size = "page",
  headingLevel = "h2",
  className,
}: EmptyStateProps) {
  const Heading = headingLevel;
  return (
    <div
      className={cx(
        "flex flex-col items-center text-center",
        size === "page" ? "gap-3 px-4 py-12 sm:py-16" : "gap-2 rounded-card border border-dashed border-line-strong px-4 py-8",
        className,
      )}
    >
      {icon ? (
        <div
          aria-hidden="true"
          className={cx(
            "bg-oly flex items-center bg-paper justify-center rounded-card border border-line text-primary",
            size === "page" ? "mb-2 size-24" : "size-16",
          )}
        >
          {icon}
        </div>
      ) : null}
      <Heading className={cx("font-extrabold tracking-heading text-ink", size === "page" ? "text-heading" : "text-base")}>{title}</Heading>
      {description ? <div className="max-w-md text-base text-ink-soft">{description}</div> : null}
      {action || secondaryAction ? (
        <div className="mt-2 flex flex-col items-center gap-2 sm:flex-row">
          {action}
          {secondaryAction}
        </div>
      ) : null}
    </div>
  );
}
