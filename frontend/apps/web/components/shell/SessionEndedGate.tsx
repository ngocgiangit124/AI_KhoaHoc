"use client";

import { useEffect, useState } from "react";
import { usePathname } from "next/navigation";
import { FORCED_LOGOUT_EVENT, LOGIN_REQUIRED_EVENT, safeRedirect, type AuthEventDetail } from "@vitaminvui/api-client";
import { SessionEndedDialog, type SessionEndedReason } from "@vitaminvui/ui/v2";
import { routes } from "@/lib/routes";

/** Trang xác thực: ở đây không có phiên để mất, nên không hiện hộp thoại "phiên kết thúc" (tránh vòng lặp tới chính trang đăng nhập). */
function isAuthPath(href: string): boolean {
  const pathname = new URL(href, "http://x").pathname; // bỏ query/hash: `/dang-nhap?x=1` vẫn là trang đăng nhập
  return [routes.login, routes.register, routes.forgotPassword].some((p) => pathname === p || pathname.startsWith(`${p}/`));
}

/** Ngữ cảnh để hộp thoại thêm câu "tiến độ/bài làm đã được lưu" khi đang ở màn học/quiz (FW4/FW5). */
function contextFor(pathname: string): "lesson" | "quiz" | "page" {
  if (/^\/hoc\/[^/]+\/quiz\//.test(pathname)) return "quiz";
  if (pathname.startsWith("/hoc/")) return "lesson";
  return "page";
}

function currentPath(): string {
  return safeRedirect(window.location.pathname + window.location.search);
}

export function loginUrl(next: string, notice?: string): string {
  const params = new URLSearchParams();
  if (next !== "/" && !isAuthPath(next)) params.set("next", next);
  if (notice) params.set("trang-thai", notice);
  const qs = params.toString();
  return qs ? `${routes.login}?${qs}` : routes.login;
}

/**
 * Hộp thoại phiên kết thúc (US-014 §2.1, design-system-v2 §12.8; PO 2026-10-07). Gắn MỘT lần ở layout gốc, lắng sự kiện do
 * `packages/api-client` phát (authFetch / `/auth/me`):
 * - `forced-logout` (`SESSION_REPLACED`) và `login-required` + `SESSION_REVOKED`: hộp thoại KHÔNG đóng được, báo lý do + "Đăng nhập lại".
 * - `login-required` còn lại (`SESSION_EXPIRED`, `UNAUTHENTICATED`): chuyển thẳng tới đăng nhập kèm `?next=` và thông báo "hết phiên".
 * Hộp thoại gắn với trang đang mở: sang trang khác (bấm "Đăng nhập lại") là tự đóng.
 */
export function SessionEndedGate() {
  const pathname = usePathname();
  const [ended, setEnded] = useState<{ reason: SessionEndedReason; path: string; href: string } | null>(null);

  // Điều chỉnh state khi render (không dùng effect): chỉ đóng khi người dùng ĐÃ tới trang đăng nhập/đăng ký/quên mật khẩu.
  // KHÔNG đóng theo mọi thay đổi path: nút Back của trình duyệt đổi pathname nhưng phiên vẫn đã mất.
  // FW4/FW5: màn học/quiz phải tự `pause()` player khi hộp thoại mở (`<dialog>` modal làm nền inert nhưng không dừng video/âm thanh).
  if (ended && isAuthPath(pathname)) setEnded(null);

  useEffect(() => {
    function onEnded(reason: SessionEndedReason) {
      if (isAuthPath(window.location.pathname)) return;
      setEnded({ reason, path: window.location.pathname, href: currentPath() });
    }
    function onForced() {
      onEnded("replaced");
    }
    function onLoginRequired(e: Event) {
      const code = (e as CustomEvent<AuthEventDetail>).detail?.code;
      if (code === "SESSION_REVOKED") {
        onEnded("revoked");
        return;
      }
      if (isAuthPath(window.location.pathname)) return;
      window.location.assign(loginUrl(currentPath(), "het-phien"));
    }
    window.addEventListener(FORCED_LOGOUT_EVENT, onForced);
    window.addEventListener(LOGIN_REQUIRED_EVENT, onLoginRequired);
    return () => {
      window.removeEventListener(FORCED_LOGOUT_EVENT, onForced);
      window.removeEventListener(LOGIN_REQUIRED_EVENT, onLoginRequired);
    };
  }, []);

  if (!ended) return null;
  return (
    <SessionEndedDialog
      open
      reason={ended.reason}
      loginHref={loginUrl(ended.href)}
      forgotHref={routes.forgotPassword}
      context={contextFor(ended.path)}
    />
  );
}
