"use client";

import { useEffect, useRef, useState } from "react";

export interface CountdownProps {
  /** Thời điểm hết hiệu lực (ISO 8601), ví dụ `resend_available_at` (api-contract §2.2). */
  targetTime: string;
  /** Gọi đúng 1 lần khi đếm ngược về 0. */
  onExpire?: () => void;
  className?: string;
}

/**
 * Đếm ngược mm:ss tới `targetTime` (US-001 §2.2 "Gửi lại sau 00:47"). Không render gì ở lần
 * render đầu tiên (server & client đều `null`) rồi mới tính thời gian còn lại trong
 * `useEffect` — tránh lệch giờ server/trình duyệt gây lỗi hydration (quy ước dự án: mọi định
 * dạng ngày giờ phải tránh mismatch, xem `packages/ui/src/format.ts`).
 */
export function Countdown({ targetTime, onExpire, className }: CountdownProps) {
  const [remainingSeconds, setRemainingSeconds] = useState<number | null>(null);
  const onExpireRef = useRef(onExpire);
  onExpireRef.current = onExpire;

  useEffect(() => {
    function computeRemaining(): number {
      return Math.max(0, Math.ceil((new Date(targetTime).getTime() - Date.now()) / 1000));
    }

    const initial = computeRemaining();
    setRemainingSeconds(initial);
    if (initial <= 0) {
      onExpireRef.current?.();
      return;
    }

    const id = setInterval(() => {
      const next = computeRemaining();
      setRemainingSeconds(next);
      if (next <= 0) {
        clearInterval(id);
        onExpireRef.current?.();
      }
    }, 1000);
    return () => clearInterval(id);
  }, [targetTime]);

  if (remainingSeconds === null || remainingSeconds <= 0) return null;

  const mm = String(Math.floor(remainingSeconds / 60)).padStart(2, "0");
  const ss = String(remainingSeconds % 60).padStart(2, "0");

  return (
    <span className={className} aria-live="polite" aria-atomic="true">
      {mm}:{ss}
    </span>
  );
}
