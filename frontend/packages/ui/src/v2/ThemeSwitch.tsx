"use client";

import { useEffect, useState } from "react";
import { cx } from "./cx";
import { IconCircleHalf, IconMoon, IconSun } from "./icons";

export type ThemeMode = "light" | "dark" | "system";
const STORAGE_KEY = "vv-theme";

const OPTIONS: Array<{ mode: ThemeMode; label: string; icon: React.ReactNode }> = [
  { mode: "light", label: "Sáng", icon: <IconSun size={16} /> },
  { mode: "dark", label: "Tối", icon: <IconMoon size={16} /> },
  { mode: "system", label: "Theo máy", icon: <IconCircleHalf size={16} /> },
];

/**
 * Chọn giao diện sáng/tối/theo máy: đặt `data-theme` lên phần tử `.theme-v2` gần nhất và nhớ trong
 * localStorage. Mặc định "Sáng" (chờ PO quyết, design-system-v2 §13).
 */
export function ThemeSwitch({ className }: { className?: string }) {
  const [mode, setMode] = useState<ThemeMode>("light");

  useEffect(() => {
    const saved = window.localStorage.getItem(STORAGE_KEY) as ThemeMode | null;
    if (saved && saved !== "light") apply(saved);
  }, []);

  function apply(next: ThemeMode) {
    setMode(next);
    document.querySelectorAll<HTMLElement>(".theme-v2").forEach((el) => el.setAttribute("data-theme", next));
    window.localStorage.setItem(STORAGE_KEY, next);
  }

  return (
    <div role="radiogroup" aria-label="Giao diện" className={cx("inline-flex rounded-control border border-line bg-surface p-0.5", className)}>
      {OPTIONS.map((o) => (
        <button
          key={o.mode}
          type="button"
          role="radio"
          aria-checked={mode === o.mode}
          onClick={() => apply(o.mode)}
          className={cx(
            "focus-ring inline-flex min-h-9 items-center gap-1.5 rounded-control px-2.5 text-sm font-semibold",
            mode === o.mode ? "bg-primary text-on-primary" : "text-ink-soft hover:text-ink",
          )}
        >
          {o.icon}
          {o.label}
        </button>
      ))}
    </div>
  );
}
