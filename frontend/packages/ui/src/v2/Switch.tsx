"use client";

import type { ButtonHTMLAttributes } from "react";
import { cx } from "./cx";

export interface SwitchProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, "onChange" | "role"> {
  checked: boolean;
  onCheckedChange?: (next: boolean) => void;
  /** Tên đọc cho trình đọc màn hình (bắt buộc khi không có nhãn hiện kèm). */
  label: string;
  /** Hiện chữ "Bật/Tắt" cạnh công tắc (không chỉ dựa vào màu/vị trí). */
  showState?: boolean;
}

/** Công tắc bật/tắt (role=switch). Vùng chạm 44px; trạng thái có chữ đi kèm. */
export function Switch({ checked, onCheckedChange, label, showState = true, className, disabled, ...rest }: SwitchProps) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      disabled={disabled}
      onClick={() => onCheckedChange?.(!checked)}
      className={cx("focus-ring inline-flex min-h-11 items-center gap-2 rounded-control px-1 disabled:cursor-not-allowed", className)}
      {...rest}
    >
      <span
        aria-hidden="true"
        className={cx(
          "relative inline-flex h-6 w-11 shrink-0 items-center rounded-full border transition-colors duration-150",
          checked ? "border-primary bg-primary" : "border-line-strong bg-sunken",
          disabled && "opacity-60",
        )}
      >
        <span className={cx("size-4.5 rounded-full shadow-raised motion-safe:transition-transform motion-safe:duration-150", checked ? "translate-x-5.5 bg-on-primary" : "translate-x-0.5 bg-surface")} />
      </span>
      {showState ? <span className={cx("text-sm font-semibold", checked ? "text-primary" : "text-ink-soft")}>{checked ? "Bật" : "Tắt"}</span> : null}
    </button>
  );
}
