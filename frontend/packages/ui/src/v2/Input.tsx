"use client";

import { useState, type InputHTMLAttributes, type ReactNode, type Ref, type SelectHTMLAttributes, type TextareaHTMLAttributes } from "react";
import { cx } from "./cx";
import { IconChevronDown, IconEye, IconEyeOff } from "./icons";

/**
 * Viền ô nhập dùng `line-strong` (3.6:1 với nền) để đạt WCAG 1.4.11 — viền `line` chỉ 1.3:1.
 * Chữ 16px để iOS không tự phóng to. Cao 44px (`size="md"`) hoặc 36px (`sm`, chỉ trang quản trị).
 */
export const CONTROL_BASE =
  "w-full rounded-control border border-line-strong bg-surface text-ink placeholder:text-ink-soft " +
  "transition-colors duration-150 hover:border-ink-soft focus:border-primary focus:outline-2 focus:outline-offset-0 focus:outline-primary/30 " +
  "disabled:cursor-not-allowed disabled:border-line disabled:bg-sunken disabled:text-ink-soft " +
  "aria-[invalid=true]:border-danger aria-[invalid=true]:focus:outline-danger/30";

const CONTROL_SIZES = { sm: "h-9 px-3 text-sm", md: "h-11 px-3.5 text-base" } as const;

export interface TextInputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, "size"> {
  size?: keyof typeof CONTROL_SIZES;
  /** Icon trong ô, bên trái (ví dụ kính lúp). */
  leadingIcon?: ReactNode;
  ref?: Ref<HTMLInputElement>;
}

export function TextInput({ size = "md", leadingIcon, className, type = "text", ...rest }: TextInputProps) {
  if (!leadingIcon) {
    return <input type={type} className={cx(CONTROL_BASE, CONTROL_SIZES[size], className)} {...rest} />;
  }
  return (
    <div className="relative">
      <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-soft">{leadingIcon}</span>
      <input type={type} className={cx(CONTROL_BASE, CONTROL_SIZES[size], "pl-10", className)} {...rest} />
    </div>
  );
}

export type PasswordInputProps = Omit<TextInputProps, "type" | "leadingIcon">;

/** Ô mật khẩu có nút Hiện/Ẩn (nút 44px, có aria-pressed). */
export function PasswordInput({ size = "md", className, ...rest }: PasswordInputProps) {
  const [visible, setVisible] = useState(false);
  return (
    <div className="relative">
      <input
        type={visible ? "text" : "password"}
        className={cx(CONTROL_BASE, CONTROL_SIZES[size], "pr-12", className)}
        {...rest}
      />
      <button
        type="button"
        onClick={() => setVisible((v) => !v)}
        aria-pressed={visible}
        aria-label={visible ? "Ẩn mật khẩu" : "Hiện mật khẩu"}
        className="focus-ring absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-control text-ink-soft hover:text-primary"
      >
        {visible ? <IconEyeOff size={18} /> : <IconEye size={18} />}
      </button>
    </div>
  );
}

export interface SelectProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, "size"> {
  size?: keyof typeof CONTROL_SIZES;
  ref?: Ref<HTMLSelectElement>;
}

/** Select gốc của trình duyệt (bàn phím, mobile picker sẵn có), chỉ đổi giao diện. */
export function Select({ size = "md", className, children, ...rest }: SelectProps) {
  return (
    <div className="relative">
      <select className={cx(CONTROL_BASE, CONTROL_SIZES[size], "appearance-none pr-10", className)} {...rest}>
        {children}
      </select>
      <IconChevronDown size={18} className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-ink-soft" />
    </div>
  );
}

export interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  ref?: Ref<HTMLTextAreaElement>;
}

export function Textarea({ className, rows = 4, ...rest }: TextareaProps) {
  return <textarea rows={rows} className={cx(CONTROL_BASE, "px-3.5 py-2.5 text-base leading-relaxed", className)} {...rest} />;
}

export interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, "type"> {
  label: ReactNode;
  /** Dòng mô tả nhỏ dưới nhãn. */
  description?: ReactNode;
  ref?: Ref<HTMLInputElement>;
}

/** Checkbox có nhãn bấm được; cả hàng là vùng chạm >= 44px. */
export function Checkbox({ label, description, className, id, ...rest }: CheckboxProps) {
  return (
    <label htmlFor={id} className={cx("flex min-h-11 cursor-pointer items-start gap-3 py-2", className)}>
      <input
        id={id}
        type="checkbox"
        className="focus-ring mt-0.5 size-5 shrink-0 cursor-pointer rounded border-line-strong accent-primary"
        {...rest}
      />
      <span className="flex flex-col">
        <span className="text-base text-ink">{label}</span>
        {description ? <span className="text-sm text-ink-soft">{description}</span> : null}
      </span>
    </label>
  );
}
