"use client";

import { cloneElement, isValidElement, useId, type ReactElement, type ReactNode } from "react";

export interface FormFieldProps {
  label: string;
  required?: boolean;
  /** Thông điệp lỗi hiển thị ngay dưới field (design-system §1). */
  error?: string;
  hint?: ReactNode;
  /** Đúng 1 control (TextInput/PasswordInput/Select...) — nhận `id`, `aria-describedby`, `aria-invalid`. */
  children: ReactElement<{
    id?: string;
    "aria-describedby"?: string;
    "aria-invalid"?: boolean;
    "aria-required"?: boolean;
  }>;
  className?: string;
}

/** Nhãn + control + hint + lỗi, nối đủ thuộc tính a11y (label for, aria-describedby, aria-invalid). */
export function FormField({ label, required = false, error, hint, children, className = "" }: FormFieldProps) {
  const baseId = useId();
  const controlId = `${baseId}-control`;
  const hintId = `${baseId}-hint`;
  const errorId = `${baseId}-error`;

  const describedBy = [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(" ");

  const control = isValidElement(children)
    ? cloneElement(children, {
        id: controlId,
        "aria-describedby": describedBy || undefined,
        "aria-invalid": error ? true : undefined,
        "aria-required": required ? true : undefined,
      })
    : children;

  return (
    <div className={`text-sm ${className}`}>
      <label htmlFor={controlId} className="mb-1 block font-medium text-gray-900">
        {label}
        {required ? (
          <span className="text-rose-600" aria-hidden="true">
            {" "}
            *
          </span>
        ) : null}
      </label>
      {control}
      {hint ? (
        <p id={hintId} className="mt-1 text-xs text-gray-600">
          {hint}
        </p>
      ) : null}
      {error ? (
        <p id={errorId} aria-live="polite" className="mt-1 text-sm text-rose-600">
          {error}
        </p>
      ) : null}
    </div>
  );
}
