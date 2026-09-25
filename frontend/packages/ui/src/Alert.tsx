"use client";

import { useState, type ReactNode } from "react";

export type AlertVariant = "success" | "danger" | "warning" | "info";

export interface AlertProps {
  variant?: AlertVariant;
  title?: string;
  children: ReactNode;
  dismissible?: boolean;
  className?: string;
}

const VARIANT_CLASSES: Record<AlertVariant, string> = {
  success: "bg-emerald-50 text-emerald-800 border-emerald-200",
  danger: "bg-rose-50 text-rose-800 border-rose-200",
  warning: "bg-amber-50 text-amber-800 border-amber-200",
  info: "bg-sky-50 text-sky-800 border-sky-200",
};

/** Thông báo banner (design-system.md §6). */
export function Alert({ variant = "info", title, children, dismissible = false, className = "" }: AlertProps) {
  const [dismissed, setDismissed] = useState(false);
  if (dismissed) return null;

  return (
    <div
      role="alert"
      className={`flex items-start gap-3 rounded-lg border p-4 text-sm ${VARIANT_CLASSES[variant]} ${className}`}
    >
      <div className="flex-1">
        {title ? <p className="font-semibold">{title}</p> : null}
        <div className={title ? "mt-1" : ""}>{children}</div>
      </div>
      {dismissible ? (
        <button
          type="button"
          onClick={() => setDismissed(true)}
          aria-label="Đóng thông báo"
          className="shrink-0 rounded p-1 text-current/70 hover:text-current focus-visible:outline focus-visible:outline-2"
        >
          ×
        </button>
      ) : null}
    </div>
  );
}
