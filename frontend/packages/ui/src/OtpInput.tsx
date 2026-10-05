"use client";

import { useEffect, useRef, type ClipboardEvent, type KeyboardEvent } from "react";

export interface OtpInputProps {
  /** Giá trị hiện tại: chuỗi chỉ gồm chữ số, dài ≤ `length`. */
  value: string;
  onChange: (value: string) => void;
  length?: number;
  disabled?: boolean;
  /** Đang xử lý: ô chuyển `readOnly` + `aria-busy` (không `disabled` để giữ focus). */
  busy?: boolean;
  invalid?: boolean;
  /** Nhãn đọc cho cả nhóm (screen reader). */
  label?: string;
  /** Id phần tử mô tả (lỗi/gợi ý) nối vào từng ô. */
  describedBy?: string;
  autoFocus?: boolean;
  /** Gọi khi nhập đủ `length` chữ số. */
  onComplete?: (value: string) => void;
  /** Mỗi lần giá trị này đổi (và > 0), focus về ô đầu — dùng sau khi báo lỗi/xoá mã. */
  focusSignal?: number;
}

const onlyDigits = (s: string) => s.replace(/\D/g, "");

/**
 * Ô nhập mã OTP: mỗi chữ số một ô, tự nhảy ô, Backspace lùi ô, dán cả mã, chỉ nhận số.
 * Ô đầu có `autocomplete="one-time-code"` để iOS/Android gợi ý mã từ tin nhắn/email.
 */
export function OtpInput({
  value,
  onChange,
  length = 6,
  disabled = false,
  busy = false,
  invalid = false,
  label = "Mã xác thực",
  describedBy,
  autoFocus = false,
  onComplete,
  focusSignal = 0,
}: OtpInputProps) {
  const refs = useRef<Array<HTMLInputElement | null>>([]);

  useEffect(() => {
    if (focusSignal > 0) refs.current[0]?.focus();
  }, [focusSignal]);
  const digits = Array.from({ length }, (_, i) => value[i] ?? "");

  function commit(next: string) {
    const clean = onlyDigits(next).slice(0, length);
    onChange(clean);
    if (clean.length === length) onComplete?.(clean);
  }

  function focusAt(i: number) {
    refs.current[Math.max(0, Math.min(length - 1, i))]?.focus();
  }

  function onInput(i: number, raw: string) {
    const entered = onlyDigits(raw);
    if (!entered) return;
    // Nhiều ký tự một lúc (autofill/IME): đổ từ ô i trở đi.
    // Bấm ô phía sau chỗ đã nhập: ghi nối tiếp vào vị trí trống đầu tiên, không để hổng.
    const at = Math.min(i, value.length);
    const next = (value.slice(0, at) + entered + value.slice(at + 1)).slice(0, length);
    commit(next);
    focusAt(Math.min(at + entered.length, length - 1));
  }

  function onKeyDown(i: number, e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === "Backspace") {
      e.preventDefault();
      if (digits[i]) {
        commit(value.slice(0, i) + value.slice(i + 1));
      } else if (i > 0) {
        commit(value.slice(0, i - 1) + value.slice(i));
        focusAt(i - 1);
      }
    } else if (e.key === "ArrowLeft") {
      e.preventDefault();
      focusAt(i - 1);
    } else if (e.key === "ArrowRight") {
      e.preventDefault();
      focusAt(i + 1);
    }
  }

  function onPaste(e: ClipboardEvent<HTMLInputElement>) {
    e.preventDefault();
    const pasted = onlyDigits(e.clipboardData.getData("text")).slice(0, length);
    if (!pasted) return;
    commit(pasted);
    focusAt(pasted.length >= length ? length - 1 : pasted.length);
  }

  return (
    <div role="group" aria-label={label} className="flex justify-center gap-2">
      {digits.map((digit, i) => (
        <input
          key={i}
          ref={(el) => {
            refs.current[i] = el;
          }}
          type="text"
          inputMode="numeric"
          pattern="[0-9]*"
          autoComplete={i === 0 ? "one-time-code" : "off"}
          maxLength={length}
          value={digit}
          disabled={disabled}
          readOnly={busy}
          aria-busy={busy ? true : undefined}
          autoFocus={autoFocus && i === 0}
          aria-label={`${label}, chữ số ${i + 1} trên ${length}`}
          aria-invalid={invalid ? true : undefined}
          aria-describedby={describedBy}
          onChange={(e) => {
            if (!busy) onInput(i, e.target.value);
          }}
          onKeyDown={(e) => onKeyDown(i, e)}
          onPaste={onPaste}
          onFocus={(e) => e.target.select()}
          className="h-12 w-11 rounded-lg border border-gray-300 bg-white text-center text-xl font-semibold text-gray-900 focus:border-indigo-600 focus:outline-none focus:ring-1 focus:ring-indigo-600 disabled:cursor-not-allowed disabled:bg-gray-100 aria-[invalid=true]:border-rose-600"
        />
      ))}
    </div>
  );
}
