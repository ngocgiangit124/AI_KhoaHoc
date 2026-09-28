"use client";

import { forwardRef, useId, useState, type InputHTMLAttributes } from "react";

export interface PasswordInputProps
  extends Omit<InputHTMLAttributes<HTMLInputElement>, "id" | "type"> {
  label: string;
  error?: string;
  hint?: string;
  required?: boolean;
}

/** Input mật khẩu có nút hiện/ẩn (design-system.md §5.1). */
export const PasswordInput = forwardRef<HTMLInputElement, PasswordInputProps>(function PasswordInput(
  { label, error, hint, required, className = "", ...rest },
  ref,
) {
  const [visible, setVisible] = useState(false);
  const id = useId();
  const errorId = `${id}-error`;
  const hintId = `${id}-hint`;

  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium text-gray-900">
        {label} {required ? <span className="text-rose-600">*</span> : null}
      </label>
      <div className="relative">
        <input
          {...rest}
          id={id}
          ref={ref}
          type={visible ? "text" : "password"}
          required={required}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? errorId : hint ? hintId : undefined}
          className={`w-full rounded-lg border px-3 py-2.5 pr-14 text-base focus:outline-none focus:ring-2 ${
            error
              ? "border-rose-400 focus:ring-rose-500"
              : "border-gray-300 focus:border-indigo-600 focus:ring-indigo-600"
          } ${className}`}
        />
        <button
          type="button"
          onClick={() => setVisible((v) => !v)}
          className="absolute inset-y-0 right-0 flex items-center px-3 text-sm font-medium text-gray-500 hover:text-gray-700"
          aria-label={visible ? "Ẩn mật khẩu" : "Hiện mật khẩu"}
        >
          {visible ? "Ẩn" : "Hiện"}
        </button>
      </div>
      {error ? (
        <p id={errorId} role="alert" className="mt-1 text-sm text-rose-600">
          {error}
        </p>
      ) : hint ? (
        <p id={hintId} className="mt-1 text-xs text-gray-500">
          {hint}
        </p>
      ) : null}
    </div>
  );
});
