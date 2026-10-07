"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { Alert, Button } from "@vitaminvui/ui/v2";
import { AccountGatePage, type GateKind } from "@/components/v2/auth/AccountGate";
import { useAuth } from "@/lib/auth/AuthProvider";
import { useGateLive } from "@/lib/auth/useGateLive";
import { routes } from "@/lib/routes";

/**
 * Màn chặn dạng TRANG (design-system-v2 §12.8) cho `/can-xac-thuc` và `/cho-phu-huynh`, khi mở thẳng một trang cần điều kiện.
 * Quyết định theo `/auth/me`: khách -> đăng nhập rồi quay lại; đã đủ điều kiện -> danh mục (không có gì để chặn).
 */
export function AccountGateRoute({ page }: { page: "verify" | "parent" }) {
  const router = useRouter();
  const { state, refresh } = useAuth();
  const live = useGateLive();
  const here = page === "verify" ? routes.needVerify : routes.parentPending;

  const eligible =
    state.status === "user" &&
    (page === "verify" ? !state.user.is_verified : state.user.parent_consent_status === "pending" || state.user.parent_consent_status === "revoked");

  useEffect(() => {
    if (state.status === "guest") router.replace(`${routes.login}?next=${here}`);
    else if (state.status === "user" && !eligible) router.replace(routes.catalog);
  }, [state, eligible, router, here]);

  if (state.status === "error") {
    return (
      <div className="mx-auto max-w-md">
        <Alert tone="danger" title="Không tải được thông tin tài khoản" action={<Button variant="secondary" onClick={() => void refresh()}>Thử lại</Button>}>
          Vui lòng kiểm tra kết nối và thử lại.
        </Alert>
      </div>
    );
  }
  if (state.status !== "user" || !eligible) return <p className="text-center text-base text-ink-soft">Đang tải…</p>;

  const kind: GateKind = page === "verify" ? "verify" : state.user.parent_consent_status === "revoked" ? "parent-revoked" : "parent-pending";
  return <AccountGatePage kind={kind} live={live} />;
}
