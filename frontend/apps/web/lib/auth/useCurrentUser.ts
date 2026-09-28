"use client";

import { useEffect, useState } from "react";
import { authFetch } from "@/lib/api";
import { parseAuthUser, type AuthUser } from "@/lib/types/auth";

/** Phát khi trạng thái đăng nhập đổi (đăng nhập/đăng ký/đăng xuất) để các nơi khác (vd
 * `SiteHeader`) tự làm mới mà không cần tải lại cả trang. */
export const AUTH_CHANGED_EVENT = "vitaminvui:auth-changed";

export function notifyAuthChanged(): void {
  if (typeof window !== "undefined") {
    window.dispatchEvent(new Event(AUTH_CHANGED_EVENT));
  }
}

interface UseCurrentUserResult {
  user: AuthUser | null;
  isLoading: boolean;
}

/**
 * Dò trạng thái đăng nhập ở nơi KHÔNG bắt buộc đăng nhập (header công khai gọi
 * `GET /auth/me`). Dùng `suppressAuthEvents: true` vì 401 `UNAUTHENTICATED` ở đây là bình
 * thường (khách chưa đăng nhập) — không được kích hoạt `ForcedLogoutOverlay`/chuyển hướng
 * `/dang-nhap` như khi một trang THẬT SỰ yêu cầu đăng nhập gặp lỗi mất phiên (xem
 * `packages/api-client/src/authFetch.ts`).
 */
export function useCurrentUser(): UseCurrentUserResult {
  // Gộp `user`/`isLoading` vào 1 state để tránh phải gọi `setState` đồng bộ ngay đầu thân
  // effect (chỉ setState bên trong callback `.then`/`.catch` — react-hooks/set-state-in-effect).
  const [state, setState] = useState<UseCurrentUserResult>({ user: null, isLoading: true });

  useEffect(() => {
    let cancelled = false;

    function fetchCurrentUser() {
      authFetch<unknown>("/api/v1/auth/me", { suppressAuthEvents: true })
        .then((raw) => {
          if (cancelled) return;
          setState({ user: parseAuthUser(raw), isLoading: false });
        })
        .catch(() => {
          if (cancelled) return;
          // 401 UNAUTHENTICATED (khách chưa đăng nhập) hay lỗi khác đều coi là "chưa rõ
          // danh tính" — không chặn hiển thị trang công khai.
          setState({ user: null, isLoading: false });
        });
    }

    fetchCurrentUser();
    window.addEventListener(AUTH_CHANGED_EVENT, fetchCurrentUser);
    return () => {
      cancelled = true;
      window.removeEventListener(AUTH_CHANGED_EVENT, fetchCurrentUser);
    };
  }, []);

  return state;
}
