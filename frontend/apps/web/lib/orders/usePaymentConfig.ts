"use client";

import { useEffect, useState } from "react";
import { fetchPaymentConfig } from "./api";
import type { PaymentConfig } from "./schemas";

export type PaymentConfigState = { status: "loading" } | { status: "error" } | { status: "ready"; config: PaymentConfig };

const TTL_MS = 60_000;
let cached: { at: number; promise: Promise<PaymentConfig> } | null = null;

/** Một lần gọi `/config/public` cho cả trang trong 60 giây (khớp cache 60 giây phía server). Lỗi thì không nhớ để lần sau gọi lại. */
function load(): Promise<PaymentConfig> {
  if (cached && Date.now() - cached.at < TTL_MS) return cached.promise;
  const promise = fetchPaymentConfig();
  const entry = { at: Date.now(), promise };
  cached = entry;
  promise.catch(() => {
    if (cached === entry) cached = null;
  });
  return promise;
}

/** Chỉ dùng cho test. */
export function resetPaymentConfigCache(): void {
  cached = null;
}

/** Cấu hình thanh toán công khai (phương thức, kênh liên hệ). `enabled=false`: không gọi API (khách chưa đăng nhập không cần). */
export function usePaymentConfig(enabled = true): PaymentConfigState {
  const [state, setState] = useState<PaymentConfigState>({ status: "loading" });
  useEffect(() => {
    if (!enabled) return;
    let alive = true;
    load().then(
      (config) => {
        if (alive) setState({ status: "ready", config });
      },
      () => {
        if (alive) setState({ status: "error" });
      },
    );
    return () => {
      alive = false;
    };
  }, [enabled]);
  return state;
}
