import { cx } from "./cx";

export interface ProgressBarProps {
  /** 0..100 (API trả số nguyên làm tròn xuống). */
  value: number;
  /** Nhãn cho trình đọc màn hình và hiển thị bên trái (ví dụ "Tiến độ của bạn"). */
  label: string;
  /** Chữ bên phải, ví dụ "6/20 bài · 30%". Không truyền thì hiện "{value}%". */
  valueText?: string;
  hideLabel?: boolean;
  size?: "sm" | "md" | "lg";
  tone?: "primary" | "success";
  /** `progress.has_content=false` → hiện "Chưa có nội dung" thay vì 0% (US-008). */
  hasContent?: boolean;
  className?: string;
}

const HEIGHTS = { sm: "h-1.5", md: "h-2", lg: "h-3" } as const;

/** Thanh tiến độ: role=progressbar, chữ số đi kèm (không chỉ dựa vào độ dài thanh). */
export function ProgressBar({
  value,
  label,
  valueText,
  hideLabel = false,
  size = "md",
  tone = "primary",
  hasContent = true,
  className,
}: ProgressBarProps) {
  const pct = Math.max(0, Math.min(100, Math.floor(value)));
  const text = hasContent ? (valueText ?? `${pct}%`) : "Chưa có nội dung";
  return (
    <div className={cx("flex flex-col gap-1.5", className)}>
      <div className={cx("flex items-baseline justify-between gap-3 text-sm", hideLabel && "sr-only")}>
        <span className="font-medium text-ink">{label}</span>
        <span className="num font-semibold text-ink-soft">{text}</span>
      </div>
      <div
        role="progressbar"
        aria-label={label}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={hasContent ? pct : undefined}
        aria-valuetext={text}
        className={cx("w-full overflow-hidden rounded-full bg-sunken", HEIGHTS[size])}
      >
        <div
          className={cx(
            "h-full rounded-full motion-safe:transition-[width] motion-safe:duration-300",
            tone === "success" || pct === 100 ? "bg-success" : "bg-primary",
          )}
          style={{ width: `${hasContent ? pct : 0}%` }}
        />
      </div>
    </div>
  );
}
