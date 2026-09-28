"use client";

import { forwardRef, useId, type InputHTMLAttributes } from "react";

export interface TextInputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, "id"> {
  label: string;
  error?: string;
  hint?: string;
  required?: boolean;
}

/**
 * Input có label/lỗi/hint, dùng với `react-hook-form` (`{...register('field')}`) —
 * design-system.md §5.1. `forwardRef` để `register()` gắn được `ref`.
 */
export const TextInput = forwardRef<HTMLInputElement, TextInputProps>(function TextInput(
  { label, error, hint, required, className = "", ...rest },
  ref,
) {
  const id = useId();
  const errorId = `${id}-error`;
  const hintId = `${id}-hint`;

  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-sm font-medium text-gray-900">
        {label} {required ? <span className="text-rose-600">*</span> : null}
      </label>
      <input
        {...rest}
        id={id}
        ref={ref}
        required={required}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? errorId : hint ? hintId : undefined}
        className={`w-full rounded-lg border px-3 py-2.5 text-base focus:outline-none focus:ring-2 ${
          error
            ? "border-rose-400 focus:ring-rose-500"
            : "border-gray-300 focus:border-indigo-600 focus:ring-indigo-600"
        } ${className}`}
      />
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
