"use client";

import { useEffect, useState } from "react";
import { FORCED_LOGOUT_EVENT, LOGIN_REQUIRED_EVENT, safeRedirect } from "@vitaminvui/api-client";

/**
 * Chỉ `SESSION_REPLACED` (đăng nhập thiết bị khác — nghi vấn bị chiếm tài khoản) dùng
 * overlay chặn toàn màn hình, không thể đóng (US-014 §2.1). Các mã còn lại
 * (`SESSION_EXPIRED`, `SESSION_REVOKED`, `UNAUTHENTICATED`, `STAFF_IDLE_TIMEOUT`) là
 * `login-required` — trường hợp "êm" hơn (phiên hết hạn bình thường, không phải bị người
 * khác chiếm), xử lý bằng chuyển hướng thẳng về trang đăng nhập, giữ đường dẫn hiện tại
 * qua `?next=` để đăng nhập xong quay lại đúng chỗ.
 */
const FORCED_LOGOUT_MESSAGE = {
  title: "Tài khoản vừa đăng nhập ở thiết bị khác",
  description:
    "Vì lý do an toàn, hệ thống chỉ cho phép 1 thiết bị đăng nhập cùng lúc. Vui lòng đăng nhập lại nếu đây là bạn.",
};

export interface ForcedLogoutOverlayProps {
  loginHref?: string;
  /** Cho test cắm mock thay vì điều hướng trình duyệt thật. */
  navigate?: (url: string) => void;
}

function buildLoginUrlWithReturnPath(loginHref: string): string {
  if (typeof window === "undefined") return loginHref;
  const currentPath = safeRedirect(window.location.pathname + window.location.search);
  if (currentPath === "/" || currentPath === loginHref) return loginHref;
  return `${loginHref}?next=${encodeURIComponent(currentPath)}`;
}

/**
 * Gắn 1 lần ở layout gốc web, lắng sự kiện `forced-logout`/`login-required` phát ra từ
 * `packages/api-client` (authFetch) — US-014.
 * - `forced-logout` (`SESSION_REPLACED`): hiện overlay chặn toàn màn hình.
 * - `login-required` (mọi mã khác): chuyển hướng ngay tới `loginHref` (mặc định
 *   `/dang-nhap`), không hiện overlay — không chặn tương tác của người dùng trước khi
 *   trình duyệt điều hướng.
 */
export function ForcedLogoutOverlay({
  loginHref = "/dang-nhap",
  navigate = (url) => window.location.assign(url),
}: ForcedLogoutOverlayProps) {
  const [showOverlay, setShowOverlay] = useState(false);

  useEffect(() => {
    function onForcedLogout() {
      setShowOverlay(true);
    }
    function onLoginRequired() {
      navigate(buildLoginUrlWithReturnPath(loginHref));
    }

    window.addEventListener(FORCED_LOGOUT_EVENT, onForcedLogout);
    window.addEventListener(LOGIN_REQUIRED_EVENT, onLoginRequired);
    return () => {
      window.removeEventListener(FORCED_LOGOUT_EVENT, onForcedLogout);
      window.removeEventListener(LOGIN_REQUIRED_EVENT, onLoginRequired);
    };
  }, [loginHref, navigate]);

  if (!showOverlay) return null;

  return (
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby="forced-logout-title"
      className="fixed inset-0 z-[200] flex items-center justify-center bg-gray-900/70 p-4"
    >
      <div className="w-full max-w-sm rounded-xl bg-white p-6 text-center shadow-lg">
        <p id="forced-logout-title" className="text-lg font-semibold text-gray-900">
          {FORCED_LOGOUT_MESSAGE.title}
        </p>
        <p className="mt-2 text-sm text-gray-600">{FORCED_LOGOUT_MESSAGE.description}</p>
        <a
          href={loginHref}
          className="mt-6 inline-flex h-11 w-full items-center justify-center rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700"
        >
          Đăng nhập lại
        </a>
      </div>
    </div>
  );
}
