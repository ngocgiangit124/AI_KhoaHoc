"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import { fetchCurrentUser, type AuthUser } from "./api";

export type AuthState = { status: "loading" } | { status: "guest" } | { status: "user"; user: AuthUser };

interface AuthContextValue {
  state: AuthState;
  /** Hỏi lại `/auth/me` (sau xác thực OTP, đổi liên hệ). */
  refresh: () => Promise<AuthState>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

/** Gọi `/auth/me` MỘT lần cho cả cây (nav + banner + trang OTP dùng chung). */
export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<AuthState>({ status: "loading" });

  const refresh = useCallback(async (): Promise<AuthState> => {
    const user = await fetchCurrentUser();
    const next: AuthState = user ? { status: "user", user } : { status: "guest" };
    setState(next);
    return next;
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    fetchCurrentUser(controller.signal).then((user) => {
      if (!controller.signal.aborted) setState(user ? { status: "user", user } : { status: "guest" });
    });
    return () => controller.abort();
  }, []);

  const value = useMemo(() => ({ state, refresh }), [state, refresh]);
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth phải nằm trong <AuthProvider>");
  return ctx;
}
