"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import { STAFF_GATE_EVENT, type StaffGateCode } from "./gate";
import { writeIdleMinutes } from "./idle";
import { fetchSession, type SessionResult } from "./session";

export type SessionState = { kind: "loading" } | SessionResult;

interface SessionContextValue {
  state: SessionState;
  /** Hỏi lại `/admin/auth/me` (sau MFA/đổi mật khẩu, hoặc nút "Thử lại"). */
  refresh: () => Promise<SessionState>;
}

const SessionContext = createContext<SessionContextValue | null>(null);

/** Gọi `/admin/auth/me` một lần cho cả cây (không bọc layout gốc để trang đăng nhập không gọi thừa). */
export function SessionProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<SessionState>({ kind: "loading" });

  const refresh = useCallback(async (): Promise<SessionState> => {
    setState((prev) => (prev.kind === "error" ? { kind: "loading" } : prev));
    const next = await fetchSession();
    setState(next);
    return next;
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    fetchSession(controller.signal).then((result) => {
      if (!controller.signal.aborted) setState(result);
    });
    return () => controller.abort();
  }, []);

  // Khoá giữa phiên (ACCOUNT_LOCKED từ bất kỳ lệnh gọi API nào): chuyển sang trạng thái "locked" để AuthGate dựng
  // màn khoá thay cho khung + màn con (không để lại menu/tên/cảnh báo cũ phía sau overlay của SessionWatcher).
  useEffect(() => {
    function onGate(e: Event) {
      if ((e as CustomEvent<{ code: StaffGateCode }>).detail?.code === "ACCOUNT_LOCKED") setState({ kind: "locked" });
    }
    window.addEventListener(STAFF_GATE_EVENT, onGate);
    return () => window.removeEventListener(STAFF_GATE_EVENT, onGate);
  }, []);

  // SessionWatcher (layout gốc, ngoài provider) đọc giới hạn idle từ đây.
  useEffect(() => {
    if (state.kind === "staff" && state.user.session?.idleTimeoutMinutes) {
      writeIdleMinutes(localStorage, state.user.session.idleTimeoutMinutes);
    }
  }, [state]);

  const value = useMemo(() => ({ state, refresh }), [state, refresh]);
  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionContextValue {
  const ctx = useContext(SessionContext);
  if (!ctx) throw new Error("useSession phải nằm trong <SessionProvider>");
  return ctx;
}
