"use client";

import { useEffect, useRef, useState } from "react";
import { Button, type ButtonSize, type ButtonVariant } from "./Button";
import { IconCheck, IconCopy } from "./icons";

export interface CopyButtonProps {
  /** Chuỗi được chép (ví dụ mã đơn, không kèm khoảng trắng hay ký tự trang trí). */
  value: string;
  /** Chữ trên nút, ví dụ "Sao chép mã đơn". */
  label?: string;
  /** Câu đọc cho trình đọc màn hình sau khi chép, ví dụ "Đã sao chép mã đơn VV2610…". */
  copiedMessage?: string;
  variant?: ButtonVariant;
  size?: ButtonSize;
  block?: boolean;
  className?: string;
}

/**
 * Nút sao chép: đổi thành "Đã sao chép" + dấu tick trong 2 giây, kèm vùng `role=status` để trình đọc màn hình biết.
 * Trình duyệt chặn clipboard (http, quyền) → chữ đổi thành "Hãy chọn và sao chép mã" (mã vẫn hiện để tự chọn).
 */
export function CopyButton({
  value,
  label = "Sao chép",
  copiedMessage,
  variant = "secondary",
  size = "md",
  block,
  className,
}: CopyButtonProps) {
  const [state, setState] = useState<"idle" | "copied" | "failed">("idle");
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => () => {
    if (timer.current) clearTimeout(timer.current);
  }, []);

  async function copy() {
    try {
      await navigator.clipboard.writeText(value);
      setState("copied");
    } catch {
      setState("failed");
    }
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => setState("idle"), 2500);
  }

  return (
    <>
      <Button
        type="button"
        variant={variant}
        size={size}
        block={block}
        className={className}
        onClick={copy}
        leadingIcon={state === "copied" ? <IconCheck size={18} /> : <IconCopy size={18} />}
      >
        {state === "copied" ? "Đã sao chép" : state === "failed" ? "Hãy chọn và sao chép mã" : label}
      </Button>
      <span role="status" className="sr-only">
        {state === "copied" ? (copiedMessage ?? `Đã sao chép ${value}`) : ""}
      </span>
    </>
  );
}
