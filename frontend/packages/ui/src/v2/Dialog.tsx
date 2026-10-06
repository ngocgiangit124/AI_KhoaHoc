"use client";

import { useEffect, useId, useRef, type ReactNode } from "react";
import { Button, IconButton } from "./Button";
import { cx } from "./cx";
import { IconX } from "./icons";

export interface DialogProps {
  open: boolean;
  onClose: () => void;
  title: ReactNode;
  description?: ReactNode;
  children?: ReactNode;
  /** Hàng nút cuối. Nút chính đặt bên phải trên desktop, trên cùng trên mobile. */
  footer?: ReactNode;
  size?: "sm" | "md" | "lg";
  /** `false`: không đóng bằng Esc/bấm nền/nút X (ví dụ đang nộp bài). */
  dismissible?: boolean;
  /** Mobile hiện dạng bottom-sheet (bộ lọc, menu); mặc định hộp giữa màn hình. */
  sheetOnMobile?: boolean;
}

const WIDTHS = { sm: "sm:max-w-sm", md: "sm:max-w-lg", lg: "sm:max-w-2xl" } as const;

/**
 * Hộp thoại dựa trên `<dialog>` gốc: `showModal()` tự bẫy focus, tự trả focus về nút mở, Esc để đóng,
 * phần còn lại của trang bị `inert`. Tiêu đề nối `aria-labelledby`.
 */
export function Dialog({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  size = "md",
  dismissible = true,
  sheetOnMobile = false,
}: DialogProps) {
  const ref = useRef<HTMLDialogElement>(null);
  const titleId = useId();
  const descId = useId();

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    if (open && !el.open) el.showModal();
    if (!open && el.open) el.close();
  }, [open]);

  return (
    <dialog
      ref={ref}
      aria-labelledby={titleId}
      aria-describedby={description ? descId : undefined}
      onCancel={(e) => {
        e.preventDefault();
        if (dismissible) onClose();
      }}
      onClick={(e) => {
        if (dismissible && e.target === e.currentTarget) onClose();
      }}
      className={cx(
        "max-h-[100dvh] w-full max-w-none bg-transparent p-0 text-ink backdrop:bg-scrim open:flex",
        sheetOnMobile ? "mx-0 mb-0 mt-auto sm:m-auto sm:px-4" : "m-auto px-4",
        WIDTHS[size],
      )}
    >
      <div
        className={cx(
          "flex max-h-[90dvh] w-full flex-col overflow-hidden bg-surface shadow-overlay",
          sheetOnMobile ? "rounded-t-sheet sm:rounded-sheet motion-safe:animate-sheet-in" : "rounded-sheet motion-safe:animate-rise-in",
        )}
      >
        <div className="flex items-start gap-3 px-5 pt-5 sm:px-6 sm:pt-6">
          <div className="flex flex-1 flex-col gap-1">
            <h2 id={titleId} className="text-heading font-extrabold tracking-heading text-ink">
              {title}
            </h2>
            {description ? (
              <div id={descId} className="text-base text-ink-soft">
                {description}
              </div>
            ) : null}
          </div>
          {dismissible ? <IconButton label="Đóng" icon={<IconX />} onClick={onClose} className="-mr-2 -mt-2" /> : null}
        </div>
        {children ? <div className="overflow-y-auto px-5 py-4 sm:px-6">{children}</div> : <div className="h-4" />}
        {footer ? (
          <div className="flex flex-col-reverse gap-2 border-t border-line px-5 py-4 sm:flex-row sm:justify-end sm:px-6">{footer}</div>
        ) : null}
      </div>
    </dialog>
  );
}

export interface ConfirmDialogProps {
  open: boolean;
  onClose: () => void;
  onConfirm: () => void;
  title: ReactNode;
  description?: ReactNode;
  confirmLabel: string;
  cancelLabel?: string;
  /** `danger` cho xoá/khoá/không hoàn tác được. */
  tone?: "primary" | "danger";
  loading?: boolean;
  loadingText?: string;
  children?: ReactNode;
}

/** Xác nhận hành động. Nút huỷ nhận focus đầu tiên khi tone=danger để tránh bấm nhầm Enter. */
export function ConfirmDialog({
  open,
  onClose,
  onConfirm,
  title,
  description,
  confirmLabel,
  cancelLabel = "Huỷ",
  tone = "primary",
  loading = false,
  loadingText,
  children,
}: ConfirmDialogProps) {
  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={title}
      description={description}
      size="sm"
      dismissible={!loading}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={loading} autoFocus={tone === "danger"}>
            {cancelLabel}
          </Button>
          <Button variant={tone === "danger" ? "danger" : "primary"} onClick={onConfirm} loading={loading} loadingText={loadingText}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      {children}
    </Dialog>
  );
}
