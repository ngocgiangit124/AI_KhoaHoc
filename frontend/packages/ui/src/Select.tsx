"use client";

import type { Ref, SelectHTMLAttributes } from "react";
import { INPUT_CLASSES } from "./TextInput";

export interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  ref?: Ref<HTMLSelectElement>;
}

/** Ô chọn native (a11y tốt nhất trên mobile). Dùng trong `<FormField>`. */
export function Select({ className = "", children, ...rest }: SelectProps) {
  return (
    <select className={`${INPUT_CLASSES} ${className}`} {...rest}>
      {children}
    </select>
  );
}
