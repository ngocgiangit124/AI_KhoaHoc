"use client";

import { useEffect, useState } from "react";
import { ApiError } from "@vitaminvui/api-client";
import { courseRefFromError } from "@/lib/learn/errors";
import { classifyMyLoadError, type MyLoadFailure } from "@/lib/my/errors";

export type MyLoaded<T> =
  | { status: "loading" }
  | { status: "ok"; data: T }
  | { status: "failed"; kind: MyLoadFailure; courseSlug: string | null };

/** `load` PHẢI là hàm module (tham chiếu ổn định); `arg` là số/chuỗi. Có `retry` để bấm "Thử lại". */
export function useMyLoad<T, A extends number | string>(load: (arg: A, signal: AbortSignal) => Promise<T>, arg: A): [MyLoaded<T>, () => void] {
  const [state, setState] = useState<MyLoaded<T>>({ status: "loading" });
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    load(arg, controller.signal).then(
      (data) => {
        if (!controller.signal.aborted) setState({ status: "ok", data });
      },
      (err: unknown) => {
        if (controller.signal.aborted) return;
        setState({ status: "failed", kind: classifyMyLoadError(err), courseSlug: err instanceof ApiError ? (courseRefFromError(err)?.slug ?? null) : null });
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
