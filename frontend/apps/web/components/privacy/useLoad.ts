"use client";

import { useCallback, useEffect, useState } from "react";
import { loadErrorMessage } from "@/lib/privacy/errors";

export type Loaded<T> = { status: "loading" } | { status: "ok"; data: T } | { status: "failed"; message: string };

/** Tải một lần khi mount (AbortController), có `reload` cho nút "Thử lại" và `set` để cập nhật dữ liệu tại chỗ. `load` PHẢI là hàm module (ổn định). */
export function useLoad<T>(load: (signal: AbortSignal) => Promise<T>): { state: Loaded<T>; reload: () => void; set: (data: T) => void } {
  const [state, setState] = useState<Loaded<T>>({ status: "loading" });
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    load(controller.signal).then(
      (data) => {
        if (!controller.signal.aborted) setState({ status: "ok", data });
      },
      (err: unknown) => {
        if (!controller.signal.aborted) setState({ status: "failed", message: loadErrorMessage(err) });
      },
    );
    return () => controller.abort();
  }, [load, attempt]);

  const reload = useCallback(() => {
    setState({ status: "loading" });
    setAttempt((a) => a + 1);
  }, []);
  const set = useCallback((data: T) => setState({ status: "ok", data }), []);
  return { state, reload, set };
}
