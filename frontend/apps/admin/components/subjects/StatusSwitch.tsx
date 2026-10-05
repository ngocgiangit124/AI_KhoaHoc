"use client";

import type { SubjectStatus } from "@/lib/subjects/types";

/** Công tắc Hiện/Ẩn (role="switch"), vùng chạm 44px. */
export function StatusSwitch({
  status,
  name,
  busy,
  onToggle,
}: {
  status: SubjectStatus;
  name: string;
  busy: boolean;
  onToggle: () => void;
}) {
  const on = status === "active";
  return (
    <button
      type="button"
      role="switch"
      aria-checked={on}
      aria-label={`Hiển thị chuyên đề ${name}`}
      disabled={busy}
      onClick={onToggle}
      className="inline-flex h-11 w-14 items-center justify-center rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
    >
      <span className={`flex h-6 w-11 items-center rounded-full p-0.5 transition-colors ${on ? "bg-emerald-600" : "bg-gray-400"}`}>
        <span className={`h-5 w-5 rounded-full bg-white shadow transition-transform ${on ? "translate-x-5" : "translate-x-0"}`} />
      </span>
    </button>
  );
}
