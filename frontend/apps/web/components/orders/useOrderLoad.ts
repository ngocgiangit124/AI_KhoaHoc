"use client";

import { useEffect, useState } from "react";
import { classifyOrderLoadError, type OrderLoadFailure } from "@/lib/orders/errors";

export type OrderLoaded<T> = { status: "loading" } | { status: "ok"; data: T } | { status: "failed"; kind: OrderLoadFailure };

/** `load` PHẢI là hàm module (tham chiếu ổn định); `arg` là số/chuỗi. Có `retry` cho nút "Thử lại". */
export function useOrderLoad<T, A extends number | string>(load: (arg: A, signal: AbortSignal) => Promise<T>, arg: A): [OrderLoaded<T>, () => void] {
  const [state, setState] = useState<OrderLoaded<T>>({ status: "loading" });
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    load(arg, controller.signal).then(
      (data) => {
        if (!controller.signal.aborted) setState({ status: "ok", data });
      },
      (err: unknown) => {
        if (!controller.signal.aborted) setState({ status: "failed", kind: classifyOrderLoadError(err) });
      },
    );
    return () => controller.abort();
  }, [load, arg, attempt]);
  return [
    state,
    () => {
      setState({ status: "loading" });
      setAttempt((a) => a + 1);
    },
  ];
}
