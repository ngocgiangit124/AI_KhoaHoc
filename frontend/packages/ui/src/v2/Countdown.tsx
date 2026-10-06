"use client";

import { useEffect, useRef, useState } from "react";
import { cx } from "./cx";
import { formatClock } from "./format";
import { IconClock } from "./icons";

export interface CountdownProps {
  /**
   * Số giây còn lại do server tính (`remaining_seconds` của lượt quiz, tính từ `server_now`).
   * Đồng hồ đếm theo đồng hồ đơn điệu của trình duyệt kể từ lúc nhận, không theo giờ máy.
   */
  remainingSeconds: number;
  onExpire?: () => void;
  /** Ngưỡng đổi màu cảnh báo (giây). */
  warnAt?: number;
  dangerAt?: number;
  className?: string;
}

/**
 * Đồng hồ đếm ngược: 3 mức màu (thường → cảnh báo ≤ 5 phút → nguy cấp ≤ 1 phút) kèm chữ, không chỉ màu.
 * Trình đọc màn hình chỉ được báo ở mốc 5 phút và 1 phút (không đọc từng giây).
 */
export function Countdown({ remainingSeconds, onExpire, warnAt = 300, dangerAt = 60, className }: CountdownProps) {
  const [left, setLeft] = useState(remainingSeconds);
  const [announce, setAnnounce] = useState("");
  const deadline = useRef<number | null>(null);
  const expired = useRef(false);
  const onExpireRef = useRef(onExpire);
  useEffect(() => {
    onExpireRef.current = onExpire;
  }, [onExpire]);

  useEffect(() => {
    deadline.current = performance.now() + remainingSeconds * 1000;
    expired.current = false;
    const timer = setInterval(() => {
      const ms = (deadline.current ?? 0) - performance.now();
      const s = Math.max(0, Math.ceil(ms / 1000));
      setLeft(s);
      if (s === warnAt) setAnnounce(`Còn ${Math.round(warnAt / 60)} phút làm bài.`);
      if (s === dangerAt) setAnnounce("Còn 1 phút. Bài sẽ tự nộp khi hết giờ.");
      if (s === 0 && !expired.current) {
        expired.current = true;
        clearInterval(timer);
        onExpireRef.current?.();
      }
    }, 250);
    return () => clearInterval(timer);
  }, [remainingSeconds, warnAt, dangerAt]);

  const level = left <= dangerAt ? "danger" : left <= warnAt ? "warning" : "normal";
  return (
    <div
      className={cx(
        "num inline-flex h-11 items-center gap-2 rounded-control px-3 text-base font-extrabold",
        level === "normal" && "bg-sunken text-ink",
        level === "warning" && "bg-warning-soft text-warning",
        level === "danger" && "bg-danger text-on-status",
        className,
      )}
    >
      <IconClock size={18} />
      <span>
        <span className="sr-only">Thời gian còn lại: </span>
        {formatClock(left)}
      </span>
      <span className="sr-only" aria-live="assertive">
        {announce}
      </span>
    </div>
  );
}
