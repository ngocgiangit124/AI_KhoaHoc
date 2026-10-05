"use client";

import { useState } from "react";
import { INPUT_CLASSES, type TextInputProps } from "./TextInput";

export type PasswordInputProps = Omit<TextInputProps, "type">;

/** Ô mật khẩu có nút hiện/ẩn (design US-001 §2.1). */
export function PasswordInput({ className = "", ...rest }: PasswordInputProps) {
  const [visible, setVisible] = useState(false);

  return (
    <div className="relative">
      <input type={visible ? "text" : "password"} className={`${INPUT_CLASSES} pr-20 ${className}`} {...rest} />
      <button
        type="button"
        onClick={() => setVisible((v) => !v)}
        aria-pressed={visible}
        aria-label="Hiện mật khẩu"
        className="absolute inset-y-0 right-0 rounded-r-lg px-3 text-sm font-medium text-indigo-700 hover:text-indigo-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600"
      >
        {visible ? "Ẩn" : "Hiện"}
      </button>
    </div>
  );
}
