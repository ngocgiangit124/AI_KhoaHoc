"use client";

import { useEffect, useRef, useState, type Ref } from "react";
import { cx } from "./cx";

export interface OtpInputProps {
  /** Chuỗi chỉ gồm chữ số, dài ≤ `length`. */
  value: string;
  onChange: (value: string) => void;
  length?: number;
  /** Gọi khi nhập đủ `length` chữ số (tự gửi). */
  onComplete?: (value: string) => void;
  disabled?: boolean;
  /** Đang gửi: ô `readOnly` + `aria-busy` (không `disabled` để giữ focus). */
  busy?: boolean;
  autoFocus?: boolean;
  name?: string;
  /** Mỗi lần số này tăng, focus lại ô và chọn hết (sau khi báo lỗi/gửi mã mới). */
  focusSignal?: number;
  /* Nhận tự động từ `<Field>`: */
  id?: string;
  "aria-describedby"?: string;
  "aria-invalid"?: boolean;
  "aria-required"?: boolean;
  ref?: Ref<HTMLInputElement>;
}

const onlyDigits = (s: string) => s.replace(/\D/g, "");

/**
 * Ô nhập mã OTP v2: MỘT `<input>` thật (một nhãn, một lỗi, dán cả mã, trình quản lý mật khẩu và
 * gợi ý mã của iOS/Android qua `autocomplete="one-time-code"`), vẽ thành 6 ô vuông để dễ đếm.
 * Không chặn dán (WCAG 2.2 — 3.3.8 Accessible Authentication). Dùng trong `<Field label="Mã xác nhận">`.
 *
 * Khác bản v1 (`OtpInput` 6 ô riêng): trình đọc màn hình chỉ gặp một ô "Mã xác nhận, 6 chữ số",
 * không phải 6 ô rời rạc.
 */
export function OtpInput({
  value,
  onChange,
  length = 6,
  onComplete,
  disabled = false,
  busy = false,
  autoFocus = false,
  name = "code",
  focusSignal = 0,
  id,
  "aria-describedby": describedBy,
  "aria-invalid": invalid,
  "aria-required": required,
  ref,
}: OtpInputProps) {
  const inner = useRef<HTMLInputElement | null>(null);
  const [focused, setFocused] = useState(false);

  useEffect(() => {
    if (focusSignal > 0) {
      inner.current?.focus();
      inner.current?.select();
    }
  }, [focusSignal]);

  const digits = Array.from({ length }, (_, i) => value[i] ?? "");
  const active = Math.min(value.length, length - 1);

  return (
    <div className={cx("relative w-full max-w-sm", disabled && "cursor-not-allowed")}>
      <div aria-hidden="true" className="grid gap-2" style={{ gridTemplateColumns: `repeat(${length}, minmax(0, 1fr))` }}>
        {digits.map((d, i) => (
          <span
            key={i}
            className={cx(
              "num flex h-14 items-center justify-center rounded-control border bg-surface text-2xl font-extrabold text-ink transition-colors duration-150",
              disabled ? "border-line bg-sunken text-ink-soft" : invalid ? "border-danger" : "border-line-strong",
              focused && !disabled && i === active && (invalid ? "outline-2 outline-danger/30" : "border-primary outline-2 outline-primary/30"),
            )}
          >
            {d || (focused && i === active ? <span className="h-7 w-0.5 bg-primary motion-safe:animate-pulse" /> : null)}
          </span>
        ))}
      </div>
      <input
        ref={(el) => {
          inner.current = el;
          if (typeof ref === "function") ref(el);
          else if (ref) ref.current = el;
        }}
        id={id}
        name={name}
        type="text"
        inputMode="numeric"
        autoComplete="one-time-code"
        pattern="[0-9]*"
        maxLength={length}
        value={value}
        disabled={disabled}
        readOnly={busy}
        aria-busy={busy || undefined}
        aria-describedby={describedBy}
        aria-invalid={invalid}
        aria-required={required}
        autoFocus={autoFocus}
        spellCheck={false}
        onFocus={() => setFocused(true)}
        onBlur={() => setFocused(false)}
        onChange={(e) => {
          if (busy) return;
          const clean = onlyDigits(e.target.value).slice(0, length);
          onChange(clean);
          if (clean.length === length && clean !== value) onComplete?.(clean);
        }}
        className="focus-ring absolute inset-0 h-full w-full cursor-text rounded-control bg-transparent text-transparent caret-transparent outline-none selection:bg-transparent disabled:cursor-not-allowed"
      />
    </div>
  );
}
