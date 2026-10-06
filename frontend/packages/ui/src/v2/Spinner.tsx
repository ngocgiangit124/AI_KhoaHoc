import { cx } from "./cx";

export interface SpinnerProps {
  className?: string;
  /** Chữ cho trình đọc màn hình. Truyền `null` khi nút đã có chữ "Đang ..." riêng. */
  label?: string | null;
}

/** Vòng xoay. Với `prefers-reduced-motion` thì đứng yên (vẫn có chữ trạng thái đi kèm). */
export function Spinner({ className = "size-4", label = "Đang tải…" }: SpinnerProps) {
  return (
    <svg
      className={cx("motion-safe:animate-spin", className)}
      viewBox="0 0 24 24"
      fill="none"
      role={label ? "status" : undefined}
      aria-label={label ?? undefined}
      aria-hidden={label ? undefined : true}
    >
      <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity="0.25" strokeWidth="3" />
      <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
    </svg>
  );
}
