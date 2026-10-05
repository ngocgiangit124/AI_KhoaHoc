"use client";

import type { InputHTMLAttributes, Ref } from "react";

export interface TextInputProps extends InputHTMLAttributes<HTMLInputElement> {
  ref?: Ref<HTMLInputElement>;
}

export const INPUT_CLASSES =
  "h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-base text-gray-900 " +
  "placeholder:text-gray-500 focus:border-indigo-600 focus:outline-none focus:ring-1 focus:ring-indigo-600 " +
  "disabled:cursor-not-allowed disabled:bg-gray-100 aria-[invalid=true]:border-rose-600";

/** Ô nhập 1 dòng (chữ 16px để iOS không tự zoom). Dùng trong `<FormField>`. */
export function TextInput({ className = "", type = "text", ...rest }: TextInputProps) {
  return <input type={type} className={`${INPUT_CLASSES} ${className}`} {...rest} />;
}
