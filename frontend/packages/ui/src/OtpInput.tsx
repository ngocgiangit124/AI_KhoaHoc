"use client";

import { useRef, type ClipboardEvent, type ChangeEvent, type KeyboardEvent } from "react";

export interface OtpInputProps {
  /** Số ô nhập, mặc định 6 (US-001 §2.2 — mã OTP 6 chữ số). */
  length?: number;
  /** Giá trị hiện tại (chuỗi số, độ dài ≤ `length`) — component được điều khiển hoàn toàn (controlled). */
  value: string;
  onChange: (value: string) => void;
  /** Gọi khi đã nhập đủ `length` chữ số (tiện submit tự động). */
  onComplete?: (value: string) => void;
  error?: string;
  disabled?: boolean;
  /** Nhãn ẩn cho screen reader (aria-label của nhóm). */
  label?: string;
}

/**
 * Ô nhập mã OTP tách từng chữ số, tự nhảy ô, chỉ nhận số (design-system.md, US-001 §2.2).
 * `inputMode="numeric"` + `autoComplete="one-time-code"` (chỉ đặt ở ô đầu tiên, theo quy ước
 * WebOTP) để trình duyệt di động gợi ý điền mã tự động và bật đúng bàn phím số.
 */
export function OtpInput({
  length = 6,
  value,
  onChange,
  onComplete,
  error,
  disabled = false,
  label = "Mã xác thực gồm 6 chữ số",
}: OtpInputProps) {
  const inputsRef = useRef<Array<HTMLInputElement | null>>([]);
  const digits = Array.from({ length }, (_, i) => value[i] ?? "");

  function focusIndex(index: number) {
    const el = inputsRef.current[Math.max(0, Math.min(length - 1, index))];
    el?.focus();
    el?.select();
  }

  function applyDigits(nextDigits: string[]) {
    const next = nextDigits.join("").slice(0, length);
    onChange(next);
    if (next.replace(/\D/g, "").length === length) {
      onComplete?.(next);
    }
  }

  function handleChange(index: number, e: ChangeEvent<HTMLInputElement>) {
    const raw = e.target.value.replace(/\D/g, "");

    if (!raw) {
      const next = [...digits];
      next[index] = "";
      applyDigits(next);
      return;
    }

    if (raw.length > 1) {
      // Dán/autofill nhiều số vào 1 ô (một số trình duyệt di động làm vậy với autoComplete).
      const next = [...digits];
      let cursor = index;
      for (const char of raw) {
        if (cursor >= length) break;
        next[cursor] = char;
        cursor += 1;
      }
      applyDigits(next);
      focusIndex(Math.min(cursor, length - 1));
      return;
    }

    const next = [...digits];
    next[index] = raw;
    applyDigits(next);
    if (index < length - 1) focusIndex(index + 1);
  }

  function handleKeyDown(index: number, e: KeyboardEvent<HTMLInputElement>) {
    if (e.key === "Backspace") {
      if (!digits[index] && index > 0) {
        e.preventDefault();
        const next = [...digits];
        next[index - 1] = "";
        applyDigits(next);
        focusIndex(index - 1);
      }
      return;
    }
    if (e.key === "ArrowLeft" && index > 0) {
      e.preventDefault();
      focusIndex(index - 1);
      return;
    }
    if (e.key === "ArrowRight" && index < length - 1) {
      e.preventDefault();
      focusIndex(index + 1);
    }
  }

  function handlePaste(index: number, e: ClipboardEvent<HTMLInputElement>) {
    const raw = e.clipboardData.getData("text").replace(/\D/g, "");
    if (!raw) return;
    e.preventDefault();
    const next = [...digits];
    let cursor = index;
    for (const char of raw) {
      if (cursor >= length) break;
      next[cursor] = char;
      cursor += 1;
    }
    applyDigits(next);
    focusIndex(Math.min(cursor, length - 1));
  }

  const errorId = "otp-input-error";

  return (
    <div>
      <div
        role="group"
        aria-label={label}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? errorId : undefined}
        className="flex justify-center gap-2"
      >
        {digits.map((digit, index) => (
          <input
            // eslint-disable-next-line react/no-array-index-key -- vị trí ô cố định, không sắp xếp lại.
            key={index}
            ref={(el) => {
              inputsRef.current[index] = el;
            }}
            value={digit}
            onChange={(e) => handleChange(index, e)}
            onKeyDown={(e) => handleKeyDown(index, e)}
            onPaste={(e) => handlePaste(index, e)}
            disabled={disabled}
            inputMode="numeric"
            autoComplete={index === 0 ? "one-time-code" : "off"}
            pattern="[0-9]*"
            maxLength={1}
            aria-label={`Chữ số ${index + 1} trên ${length}`}
            className={`h-12 w-11 rounded-lg border text-center text-xl font-bold focus:outline-none focus:ring-2 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-400 ${
              error
                ? "border-rose-400 focus:ring-rose-500"
                : "border-gray-300 focus:border-indigo-600 focus:ring-indigo-600"
            }`}
          />
        ))}
      </div>
      {error ? (
        <p id={errorId} role="alert" className="mt-2 text-center text-sm text-rose-600">
          {error}
        </p>
      ) : null}
    </div>
  );
}
