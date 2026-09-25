"use client";

import { useEffect, useRef, type ReactNode } from "react";

export interface ModalProps {
  title: string;
  children: ReactNode;
  onClose: () => void;
  footer?: ReactNode;
}

/** Hộp thoại chung (design-system.md §5.1). Đóng bằng Esc, bẫy focus cơ bản, có overlay. */
export function Modal({ title, children, onClose, footer }: ModalProps) {
  const dialogRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape") onClose();
    }
    document.addEventListener("keydown", onKeyDown);
    dialogRef.current?.focus();
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [onClose]);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-gray-900/50" onClick={onClose} aria-hidden="true" />
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="modal-title"
        tabIndex={-1}
        className="relative w-full max-w-md rounded-xl bg-white p-6 shadow-lg outline-none"
      >
        <h2 id="modal-title" className="text-lg font-semibold text-gray-900">
          {title}
        </h2>
        <div className="mt-3 text-sm text-gray-700">{children}</div>
        {footer ? <div className="mt-6 flex justify-end gap-3">{footer}</div> : null}
      </div>
    </div>
  );
}
