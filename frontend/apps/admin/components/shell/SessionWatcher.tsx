"use client";

import { useEffect, useRef, useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import { LOGIN_REQUIRED_EVENT, type AuthEventDetail } from "@vitaminvui/api-client";
import { STAFF_GATE_EVENT, type StaffGateCode } from "@/lib/api";
import { logoutStaff } from "@/lib/auth/api";
import { isIdleExpired, readIdleLimitMs, readLastActivity, writeLastActivity } from "@/lib/auth/idle";
import { LockedNotice } from "./LockedNotice";

const AUTH_PATHS = ["/dang-nhap", "/xac-thuc-mfa", "/doi-mat-khau"];
const ACTIVITY_EVENTS = ["pointerdown", "keydown", "scroll", "touchstart"] as const;
const ACTIVITY_WRITE_THROTTLE_MS = 10_000;
const IDLE_CHECK_INTERVAL_MS = 30_000;

export interface SessionWatcherProps {
  /** Cho test cắm clock/giới hạn khác. Mặc định đọc `session.idle_timeout_minutes` đã lưu (rơi về 120 phút). */
  now?: () => number;
  idleLimitMs?: number;
}

function loginUrl(pathname: string, reason: string | null): string {
  const params = new URLSearchParams();
  if (pathname.startsWith("/quan-tri")) params.set("next", pathname);
  if (reason) params.set("reason", reason);
  const qs = params.toString();
  return qs ? `/dang-nhap?${qs}` : "/dang-nhap";
}

/**
 * Gắn 1 lần ở layout gốc admin:
 * - `login-required` (401 STAFF_IDLE_TIMEOUT/UNAUTHENTICATED/...) → `/dang-nhap` kèm thông báo (idle riêng).
 * - 403 cổng staff: MFA_REQUIRED → /xac-thuc-mfa, PASSWORD_CHANGE_REQUIRED → /doi-mat-khau,
 *   ACCOUNT_LOCKED → overlay chặn toàn trang (US-016 §3), không phải lỗi chung chung.
 * - Idle phía trình duyệt: không có thao tác người dùng ≥ 120 phút (chung giữa các tab) thì đăng
 *   xuất ngay. Chỉ là phản hồi sớm — server (`staff.idle`, 12 giờ tuyệt đối) mới là nguồn sự thật.
 */
export function SessionWatcher({ now = Date.now, idleLimitMs }: SessionWatcherProps) {
  const router = useRouter();
  const pathname = usePathname();
  const [locked, setLocked] = useState(false);
  const inAdminArea = pathname.startsWith("/quan-tri");
  const onAuthPage = AUTH_PATHS.includes(pathname);

  useEffect(() => {
    function onLoginRequired(e: Event) {
      if (onAuthPage) return;
      const code = (e as CustomEvent<AuthEventDetail>).detail?.code;
      router.replace(loginUrl(pathname, code === "STAFF_IDLE_TIMEOUT" ? "idle" : "expired"));
    }
    function onGate(e: Event) {
      const code = (e as CustomEvent<{ code: StaffGateCode }>).detail?.code;
      if (onAuthPage) return;
      if (code === "ACCOUNT_LOCKED") setLocked(true);
      else if (code === "MFA_REQUIRED") router.replace(`/xac-thuc-mfa?next=${encodeURIComponent(pathname)}`);
      else if (code === "PASSWORD_CHANGE_REQUIRED") router.replace(`/doi-mat-khau?next=${encodeURIComponent(pathname)}`);
    }
    window.addEventListener(LOGIN_REQUIRED_EVENT, onLoginRequired);
    window.addEventListener(STAFF_GATE_EVENT, onGate);
    return () => {
      window.removeEventListener(LOGIN_REQUIRED_EVENT, onLoginRequired);
      window.removeEventListener(STAFF_GATE_EVENT, onGate);
    };
  }, [pathname, onAuthPage, router]);

  useEffect(() => {
    if (!inAdminArea) return;
    let lastWrite = 0;
    let expiredHandled = false;
    const touch = () => {
      const t = now();
      if (t - lastWrite >= ACTIVITY_WRITE_THROTTLE_MS) {
        lastWrite = t;
        writeLastActivity(localStorage, t);
      }
    };
    // Vào khu quản trị = vừa có hoạt động (đăng nhập/điều hướng).
    writeLastActivity(localStorage, now());
    lastWrite = now();

    const check = () => {
      if (expiredHandled) return;
      if (isIdleExpired(readLastActivity(localStorage, now()), now(), idleLimitMs ?? readIdleLimitMs(localStorage))) {
        expiredHandled = true;
        void logoutStaff()
          .catch(() => undefined)
          .finally(() => router.replace(loginUrl(pathname, "idle")));
      }
    };

    for (const ev of ACTIVITY_EVENTS) window.addEventListener(ev, touch, { passive: true });
    const onVisible = () => {
      if (document.visibilityState === "visible") check();
    };
    document.addEventListener("visibilitychange", onVisible);
    const timer = setInterval(check, IDLE_CHECK_INTERVAL_MS);

    return () => {
      for (const ev of ACTIVITY_EVENTS) window.removeEventListener(ev, touch);
      document.removeEventListener("visibilitychange", onVisible);
      clearInterval(timer);
    };
  }, [inAdminArea, pathname, router, now, idleLimitMs]);

  if (!locked) return null;
  return <LockedOverlay />;
}

/**
 * Overlay khoá giữa phiên: `<dialog>` + `showModal()` (bẫy focus, phần còn lại của trang bị `inert`, focus vào liên kết
 * "Về trang đăng nhập"). Nền đặc `bg-paper` nên không lộ khung/menu phía sau; Esc không đóng được.
 */
function LockedOverlay() {
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    const el = ref.current;
    if (el && !el.open) el.showModal();
  }, []);
  return (
    <dialog
      ref={ref}
      role="alertdialog"
      aria-modal="true"
      aria-labelledby="staff-locked-title"
      onCancel={(e) => e.preventDefault()}
      className="fixed inset-0 z-[200] m-0 h-full max-h-none w-full max-w-none items-center justify-center overflow-y-auto bg-paper p-4 text-ink backdrop:bg-paper open:flex"
    >
      <LockedNotice titleId="staff-locked-title" />
    </dialog>
  );
}
