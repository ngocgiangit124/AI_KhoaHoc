"use client";

import { useEffect, type ReactNode } from "react";
import { useRouter } from "next/navigation";
import { Alert, Button, LoadingRegion } from "@vitaminvui/ui/v2";
import { loginUrl } from "@/components/shell/SessionEndedGate";
import { useAuth } from "@/lib/auth/AuthProvider";

/**
 * Chờ `/auth/me` (AuthProvider dùng chung của khung trang) rồi mới render con — con chỉ gọi API khi đã có người dùng.
 * Khách -> trang đăng nhập kèm `?next=`; lỗi tạm thời -> "Thử lại". Chỉ là trải nghiệm: quyền thật do API kiểm tra.
 */
export function RequireUser({ next, skeleton, children }: { next: string; skeleton: ReactNode; children: ReactNode }) {
  const router = useRouter();
  const { state, refresh } = useAuth();

  useEffect(() => {
    if (state.status === "guest") router.replace(loginUrl(next));
  }, [state.status, next, router]);

  if (state.status === "error") {
    return (
      <Alert tone="danger" title="Không tải được thông tin tài khoản" action={<Button variant="secondary" onClick={() => void refresh()}>Thử lại</Button>}>
        Vui lòng kiểm tra kết nối và thử lại.
      </Alert>
    );
  }
  if (state.status !== "user") return <>{skeleton}</>;
  return <>{children}</>;
}

export function PageSkeletonRegion({ label, children }: { label: string; children: ReactNode }) {
  return (
    <LoadingRegion label={label} className="flex flex-col gap-4">
      {children}
    </LoadingRegion>
  );
}
