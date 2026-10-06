"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { IconButton } from "./Button";
import { cx } from "./cx";
import { IconAlertCircle, IconAlertTriangle, IconCheckCircle, IconInfo, IconX } from "./icons";

export type ToastTone = "success" | "info" | "warning" | "danger";

export interface ToastInput {
  tone?: ToastTone;
  title: string;
  description?: string;
  /** ms; mặc định 5000. Toast lỗi nên dùng Alert trong trang thay vì toast. */
  duration?: number;
}

interface ToastItem extends Required<Pick<ToastInput, "tone" | "title">> {
  id: number;
  description?: string;
  duration: number;
}

const ToastContext = createContext<{ show: (t: ToastInput) => void } | null>(null);

const ICONS: Record<ToastTone, ReactNode> = {
  success: <IconCheckCircle className="text-success" />,
  info: <IconInfo className="text-info" />,
  warning: <IconAlertTriangle className="text-warning" />,
  danger: <IconAlertCircle className="text-danger" />,
};

function ToastView({ item, onDismiss }: { item: ToastItem; onDismiss: (id: number) => void }) {
  const [paused, setPaused] = useState(false);
  const remaining = useRef(item.duration);
  useEffect(() => {
    if (paused) return;
    const started = Date.now();
    const timer = setTimeout(() => onDismiss(item.id), remaining.current);
    return () => {
      clearTimeout(timer);
      remaining.current -= Date.now() - started;
    };
  }, [paused, item.id, onDismiss]);

  return (
    <div
      role={item.tone === "danger" ? "alert" : "status"}
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onFocus={() => setPaused(true)}
      onBlur={() => setPaused(false)}
      className="pointer-events-auto flex w-full items-start gap-3 rounded-card border border-line bg-surface p-4 text-ink shadow-overlay motion-safe:animate-rise-in sm:w-96"
    >
      <span className="mt-0.5">{ICONS[item.tone]}</span>
      <div className="flex flex-1 flex-col gap-0.5">
        <p className="text-base font-semibold">{item.title}</p>
        {item.description ? <p className="text-sm text-ink-soft">{item.description}</p> : null}
      </div>
      <IconButton label="Đóng thông báo" icon={<IconX size={18} />} size="sm" onClick={() => onDismiss(item.id)} className="-mr-1 -mt-1" />
    </div>
  );
}

/**
 * Toast v2: góc dưới phải (desktop), dưới giữa và nằm trên bottom-nav (mobile).
 * Tự ẩn sau 5 giây, dừng đếm khi rê chuột/focus; luôn có nút đóng.
 */
export function ToastProvider({ children, bottomOffsetClass = "bottom-4" }: { children: ReactNode; bottomOffsetClass?: string }) {
  const [items, setItems] = useState<ToastItem[]>([]);
  const nextId = useRef(1);
  const dismiss = useCallback((id: number) => setItems((prev) => prev.filter((t) => t.id !== id)), []);
  const show = useCallback((t: ToastInput) => {
    const id = nextId.current++;
    setItems((prev) => [...prev.slice(-2), { id, tone: t.tone ?? "success", title: t.title, description: t.description, duration: t.duration ?? 5000 }]);
  }, []);
  const value = useMemo(() => ({ show }), [show]);

  return (
    <ToastContext.Provider value={value}>
      {children}
      <div
        aria-live="polite"
        className={cx("pointer-events-none fixed inset-x-0 z-50 flex flex-col items-center gap-2 px-4 sm:inset-x-auto sm:right-6 sm:items-end", bottomOffsetClass)}
      >
        {items.map((item) => (
          <ToastView key={item.id} item={item} onDismiss={dismiss} />
        ))}
      </div>
    </ToastContext.Provider>
  );
}

export function useToast() {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error("useToast() (v2) phải nằm trong <ToastProvider> của @vitaminvui/ui/v2.");
  return ctx;
}
