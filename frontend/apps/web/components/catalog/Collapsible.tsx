"use client";

import { useEffect, useId, useRef, useState, type ReactNode } from "react";

/** Thu gọn nội dung dài với "Xem thêm/Thu gọn" (US-003 §2.1 mục 4). Nội dung đủ ngắn thì không hiện nút. */
export function Collapsible({ children, collapsedHeight = 160 }: { children: ReactNode; collapsedHeight?: number }) {
  const ref = useRef<HTMLDivElement>(null);
  const [expanded, setExpanded] = useState(false);
  const [overflowing, setOverflowing] = useState(false);
  const id = useId();

  useEffect(() => {
    const el = ref.current;
    if (el) setOverflowing(el.scrollHeight > collapsedHeight + 24);
  }, [collapsedHeight]);

  const clamp = overflowing && !expanded;
  return (
    <div>
      <div
        id={id}
        ref={ref}
        style={clamp ? { maxHeight: collapsedHeight } : undefined}
        className={clamp ? "overflow-hidden [mask-image:linear-gradient(to_bottom,black_60%,transparent)]" : undefined}
      >
        {children}
      </div>
      {overflowing ? (
        <button
          type="button"
          aria-expanded={expanded}
          aria-controls={id}
          onClick={() => setExpanded((v) => !v)}
          className="focus-ring mt-2 inline-flex min-h-11 items-center rounded px-1 text-base font-semibold text-primary underline underline-offset-4 hover:text-primary-hover"
        >
          {expanded ? "Thu gọn" : "Xem thêm"}
        </button>
      ) : null}
    </div>
  );
}
