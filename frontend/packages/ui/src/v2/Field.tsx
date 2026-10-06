"use client";

import { cloneElement, isValidElement, useId, type ReactElement, type ReactNode } from "react";
import { cx } from "./cx";
import { IconAlertCircle } from "./icons";

type ControlProps = {
  id?: string;
  "aria-describedby"?: string;
  "aria-invalid"?: boolean;
  "aria-required"?: boolean;
  required?: boolean;
};

export interface FieldProps {
  label: ReactNode;
  required?: boolean;
  /** Ghi chú dưới nhãn (luôn hiện), ví dụ "Tối thiểu 8 ký tự". */
  hint?: ReactNode;
  /** Lỗi hiện ngay dưới ô, kèm icon (không chỉ dựa vào màu). */
  error?: string;
  /** Đặt ở góc phải nhãn, ví dụ bộ đếm ký tự hoặc liên kết "Quên mật khẩu?". */
  aside?: ReactNode;
  /** Đúng 1 control: nhận id + aria-* tự động. */
  children: ReactElement<ControlProps>;
  className?: string;
  /** id cho control (để liên kết từ hộp tóm tắt lỗi `#id`). Mặc định tự sinh. */
  id?: string;
}

/**
 * Nhãn luôn hiển thị + control + gợi ý + lỗi. Nối đủ `for`, `aria-describedby`, `aria-invalid`.
 * Dấu * bắt buộc có chữ ẩn "(bắt buộc)" cho trình đọc màn hình.
 */
export function Field({ label, required = false, hint, error, aside, children, className, id }: FieldProps) {
  const baseId = useId();
  const controlId = id ?? `${baseId}-control`;
  const hintId = `${baseId}-hint`;
  const errorId = `${baseId}-error`;
  const describedBy = [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(" ") || undefined;

  const control = isValidElement(children)
    ? cloneElement(children, {
        id: controlId,
        "aria-describedby": describedBy,
        "aria-invalid": error ? true : undefined,
        "aria-required": required || undefined,
      })
    : children;

  return (
    <div className={cx("flex flex-col gap-1.5", className)}>
      <div className="flex items-baseline justify-between gap-3">
        <label htmlFor={controlId} className="text-sm font-semibold text-ink">
          {label}
          {required ? (
            <>
              <span className="text-danger" aria-hidden="true">
                {" "}
                *
              </span>
              <span className="sr-only"> (bắt buộc)</span>
            </>
          ) : null}
        </label>
        {aside ? <div className="text-sm">{aside}</div> : null}
      </div>
      {control}
      {hint ? (
        <p id={hintId} className="text-sm text-ink-soft">
          {hint}
        </p>
      ) : null}
      {error ? (
        <p id={errorId} className="flex items-start gap-1.5 text-sm font-medium text-danger">
          <IconAlertCircle size={16} className="mt-0.5" />
          <span>{error}</span>
        </p>
      ) : null}
    </div>
  );
}
