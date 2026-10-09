"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import { fetchCurrentUser, type AuthUser, type MeResult } from "./api";

function toState(result: MeResult): AuthState {
  if (result.kind === "user") return { status: "user", user: result.user };
  return { status: result.kind };
}

export type AuthState =
  | { status: "loading" }
  | { status: "error" }
  | { status: "guest" } | { status: "user"; user: AuthUser };

interface AuthContextValue {
  state: AuthState;
  /** Hỏi lại `/auth/me` (sau đổi liên hệ, hoặc nút "Thử lại" khi lỗi tạm thời). */
  refresh: () => Promise<AuthState>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

/** Gọi `/auth/me` MỘT lần cho cả cây (nav + banner + trang OTP dùng chung). */
export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<AuthState>({ status: "loading" });

  const refresh = useCallback(async (): Promise<AuthState> => {
    setState((prev) => (prev.status === "error" ? { status: "loading" } : prev));
    const next = toState(await fetchCurrentUser());
    // Lỗi tạm thời (mạng/5xx) khi đang có người dùng: giữ nguyên trạng thái cũ, không thay cả trang/header bằng màn lỗi.
    let result = next;
    setState((prev) => {
      result = prev.status === "user" && next.status === "error" ? prev : next;
      return result;
    });
    return result;
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    fetchCurrentUser(controller.signal).then((result) => {
      if (!controller.signal.aborted) setState(toState(result));
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

/** Như `useAuth` nhưng trả `null` khi không có `<AuthProvider>` (khung trang tối giản của đăng nhập/đăng ký). */
export function useOptionalAuth(): AuthContextValue | null {
  return useContext(AuthContext);
}
