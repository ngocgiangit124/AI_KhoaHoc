"use client";

import { useEffect, useRef, useState } from "react";
import { Button } from "./Button";
import { formatClock } from "./format";
import { IconRotateCcw } from "./icons";

export interface ResendCodeProps {
  /**
   * Số giây phải chờ trước khi gửi lại: từ `resend_available_at` (POST /auth/otp/send,
   * /auth/password/forgot, /admin/auth/mfa/resend) hoặc `Retry-After` của 429. 0 = gửi được ngay.
   * Đổi giá trị (kể cả cùng số) bằng cách đổi `key` để chạy lại đồng hồ.
   */
  waitSeconds: number;
  onResend: () => void;
  /** Đang gửi mã mới. */
  loading?: boolean;
  /**
   * Mã đã hết hạn / hết lượt: nút chuyển sang dạng nổi bật (nút chính) vì đây là việc duy nhất
   * người dùng có thể làm tiếp. Mặc định là nút phụ.
   */
  emphasis?: boolean;
  /** Đã chạm trần gửi trong ngày (429 không có thời gian chờ ngắn): khoá nút + giải thích. */
  lockedReason?: string;
  block?: boolean;
  label?: string;
}

/**
 * Nút "Gửi lại mã" có đếm ngược. Khi đang chờ: nút bị khoá và chữ nói rõ còn bao lâu
 * ("Gửi lại mã sau 0:45") — không chỉ làm mờ. Trình đọc màn hình chỉ được báo một lần khi gửi được.
 */
export function ResendCode({ waitSeconds, onResend, loading = false, emphasis = false, lockedReason, block = false, label = "Gửi lại mã" }: ResendCodeProps) {
  const [left, setLeft] = useState(waitSeconds);
  const [announce, setAnnounce] = useState("");
  const deadline = useRef(0);

  useEffect(() => {
    deadline.current = performance.now() + waitSeconds * 1000;
    if (waitSeconds <= 0) return;
    const timer = setInterval(() => {
      const s = Math.max(0, Math.ceil((deadline.current - performance.now()) / 1000));
      setLeft(s);
      if (s === 0) {
        clearInterval(timer);
        setAnnounce("Bạn có thể gửi lại mã.");
      }
    }, 250);
    return () => clearInterval(timer);
  }, [waitSeconds]);

  const waiting = left > 0;
  return (
    <div className="flex flex-col gap-1.5">
      <Button
        variant={emphasis ? "primary" : "secondary"}
        size={emphasis ? "lg" : "md"}
        block={block}
        disabled={waiting || Boolean(lockedReason)}
        loading={loading}
        loadingText="Đang gửi mã…"
        leadingIcon={<IconRotateCcw size={18} />}
        onClick={onResend}
      >
        {waiting ? (
          <>
            {label} sau <span className="num">{formatClock(left)}</span>
          </>
        ) : (
          label
        )}
      </Button>
      {lockedReason ? <p className="text-sm text-ink-soft">{lockedReason}</p> : null}
      <span className="sr-only" aria-live="polite">
        {announce}
      </span>
    </div>
  );
}
