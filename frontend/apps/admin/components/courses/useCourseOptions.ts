"use client";

import { useCallback, useEffect, useState } from "react";
import { listActiveSubjects, listTeachers } from "@/lib/courses/api";
import { courseActionError } from "@/lib/courses/errors";
import type { IdName } from "@/lib/courses/types";

export interface OptionsState {
  items: IdName[];
  loading: boolean;
  error: string | null;
  retry: () => void;
}

function useLoader(enabled: boolean, load: (signal: AbortSignal) => Promise<IdName[]>): OptionsState {
  const [attempt, setAttempt] = useState(0);
  const [state, setState] = useState<{ attempt: number; items: IdName[]; error: string | null } | null>(null);

  useEffect(() => {
    if (!enabled) return;
    const controller = new AbortController();
    load(controller.signal)
      .then((items) => setState({ attempt, items, error: null }))
      .catch((err: unknown) => {
        if (!controller.signal.aborted) setState({ attempt, items: [], error: courseActionError(err) });
      });
    return () => controller.abort();
    // `load` là hàm bọc ổn định do caller truyền qua useCallback.
  }, [enabled, attempt, load]);

  const retry = useCallback(() => setAttempt((n) => n + 1), []);
  const done = state !== null && state.attempt === attempt;
  return { items: state?.items ?? [], loading: enabled && !done, error: done ? state.error : null, retry };
}

/** Chuyên đề đang hiển thị (cho bộ lọc và ô chọn). */
export function useSubjectOptions(enabled: boolean, isStaff: boolean): OptionsState {
  const load = useCallback((signal: AbortSignal) => listActiveSubjects({ isStaff, signal }), [isStaff]);
  return useLoader(enabled, load);
}

/** Giáo viên đang hoạt động (chỉ staff gọi được; giáo viên nhận 403). */
export function useTeacherOptions(enabled: boolean): OptionsState {
  const load = useCallback((signal: AbortSignal) => listTeachers(signal), []);
  return useLoader(enabled, load);
}
