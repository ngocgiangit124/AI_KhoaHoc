"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { usePathname } from "next/navigation";
import { getPendingCount } from "./api";

interface PendingOrdersValue {
  /** Số đơn `manual` đang chờ duyệt; `null` = chưa biết / không có quyền. */
  count: number | null;
  expiringSoon: number;
  /** Gọi lại `pending-count` (sau thao tác duyệt/huỷ). Gộp các lời gọi dồn dập. */
  refresh: () => void;
}

const NOOP: PendingOrdersValue = { count: null, expiringSoon: 0, refresh: () => {} };
const Ctx = createContext<PendingOrdersValue>(NOOP);

/** Chu kỳ làm mới thưa (chỉ khi tab hiện): ngoài ra làm mới khi chuyển trang, khi quay lại tab và sau mỗi thao tác. */
export const PENDING_POLL_MS = 60_000;
/** Quay lại tab: chỉ làm mới nếu lần tải trước đã quá lâu (tránh dồn dập khi chuyển tab liên tục). */
const VISIBLE_MIN_GAP_MS = 30_000;

/**
 * Số đơn chờ trên menu "Đơn hàng". Nguồn duy nhất: `GET /admin/orders/pending-count` (không nằm trong /admin/auth/me).
 * Chỉ chạy khi `enabled` (có `permissions.view_orders`). Lỗi (429, mạng...) bỏ qua, giữ số cũ — badge không quan trọng bằng thao tác.
 */
export function PendingOrdersProvider({ enabled, children }: { enabled: boolean; children: ReactNode }) {
  const pathname = usePathname();
  const [state, setState] = useState<{ count: number | null; expiringSoon: number }>({ count: null, expiringSoon: 0 });
  const lastAt = useRef(0);
  const aborter = useRef<AbortController | null>(null);

  const load = useCallback(() => {
    if (!enabled) return;
    lastAt.current = Date.now();
    aborter.current?.abort();
    const c = new AbortController();
    aborter.current = c;
    getPendingCount(c.signal)
      .then((r) => {
        if (!c.signal.aborted) setState({ count: r.pending_manual, expiringSoon: r.expiring_soon });
      })
      .catch(() => {});
  }, [enabled]);

  // Khi tải layout và mỗi lần chuyển trang. (Cleanup huỷ lời gọi đang bay; StrictMode ở dev chạy lại effect nên vẫn có 1 lời gọi hoàn tất.)
  useEffect(() => {
    load();
    return () => aborter.current?.abort();
  }, [load, pathname]);

  useEffect(() => {
    if (!enabled) return;
    const onVisible = () => {
      if (document.visibilityState === "visible" && Date.now() - lastAt.current > VISIBLE_MIN_GAP_MS) load();
    };
    document.addEventListener("visibilitychange", onVisible);
    const t = window.setInterval(() => {
      if (document.visibilityState === "visible") load();
    }, PENDING_POLL_MS);
    return () => {
      document.removeEventListener("visibilitychange", onVisible);
      window.clearInterval(t);
    };
  }, [enabled, load]);

  const value = useMemo<PendingOrdersValue>(() => ({ ...state, refresh: load }), [state, load]);
  return <Ctx.Provider value={enabled ? value : NOOP}>{children}</Ctx.Provider>;
}

export const usePendingOrders = () => useContext(Ctx);
