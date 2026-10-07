"use client";

import { useEffect } from "react";
import { usePathname } from "next/navigation";
import { Alert, ButtonLink, useToast } from "@vitaminvui/ui/v2";
import { useAuth } from "@/lib/auth/AuthProvider";
import { consumeAccountFlash } from "@/lib/auth/flash";
import { routes } from "@/lib/routes";

/**
 * Banner trạng thái tài khoản ở trang công khai (design US-001 §3, §2.4). Quyết định theo
 * `is_verified`/`parent_consent_status` của `/auth/me`, không theo tuổi client hay query string:
 * - chưa xác thực → nhắc + nút "Xác thực ngay" (kèm lưu ý phụ huynh nếu `pending`); không hiện ở `/tai-khoan`
 *   (trang đó tự có thông báo riêng, tránh hai nút "Xác thực ngay");
 * - vừa xác thực xong (cờ một lần) → toast "Xác thực tài khoản thành công" (design-system-v2 §12.8).
 */
export function AccountBanner() {
  const { state } = useAuth();
  const toast = useToast();
  const pathname = usePathname();

  useEffect(() => {
    // Đọc sessionStorage chỉ làm được sau hydrate; Strict Mode: lần 2 đọc null nên không báo hai lần.
    if (consumeAccountFlash() === "verified") toast.show({ tone: "success", title: "Xác thực tài khoản thành công" });
  }, [toast]);

  if (state.status !== "user" || state.user.is_verified || pathname === routes.account) return null;
  const { user } = state;

  return (
    <Alert
      tone="warning"
      title="Cần xác thực tài khoản"
      className="mb-2"
      action={
        <ButtonLink href={routes.verifyOtp} size="md">
          Xác thực ngay
        </ButtonLink>
      }
    >
      {user.parent_consent_status === "pending"
        ? "Vui lòng xác thực tài khoản. Chúng tôi cũng đã gửi email xác nhận tới phụ huynh của bạn — bạn cần cả 2 bước hoàn tất mới mua được khóa học."
        : "Vui lòng xác thực tài khoản để có thể mua khóa học."}
    </Alert>
  );
}
