"use client";

import { useEffect, useState } from "react";
import { ApiError } from "@vitaminvui/api-client";
import { courseRefFromError } from "@/lib/learn/errors";
import { classifyQuizLoadError, type QuizLoadFailure } from "@/lib/quiz/errors";

export type Loaded<T> = { status: "loading" } | { status: "ok"; data: T } | { status: "failed"; kind: QuizLoadFailure; courseSlug: string | null };

function toFailure(err: unknown): Loaded<never> {
  return {
    status: "failed",
    kind: classifyQuizLoadError(err),
    courseSlug: err instanceof ApiError ? (courseRefFromError(err)?.slug ?? null) : null,
  };
}

/** `load` PHẢI là hàm module (tham chiếu ổn định) và `arg` có tham chiếu ổn định (số, hoặc object đã memo). */
export function useLoaded<T, A>(load: (arg: A) => Promise<T>, arg: A): [Loaded<T>, () => void] {
  const [state, setState] = useState<Loaded<T>>({ status: "loading" });
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    let cancelled = false;
    load(arg).then(
      (data) => {
        if (!cancelled) setState({ status: "ok", data });
      },
      (err: unknown) => {
        if (!cancelled) setState(toFailure(err));
      },
    );
    return () => {
      cancelled = true;
    };
  }, [load, arg, attempt]);
  return [
    state,
    () => {
      setState({ status: "loading" });
      setAttempt((a) => a + 1);
    },
  ];
}

export { toFailure };
