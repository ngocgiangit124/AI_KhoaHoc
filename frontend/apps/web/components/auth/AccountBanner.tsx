"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Alert } from "@vitaminvui/ui";
import { useAuth } from "@/lib/auth/AuthProvider";
import { consumeAccountFlash } from "@/lib/auth/flash";

/**
 * Banner trạng thái tài khoản ở trang chủ (design US-001 §3, §2.4). Quyết định theo
 * `is_verified`/`parent_consent_status` của `/auth/me`, không theo tuổi client hay query string:
 * - chưa xác thực → nhắc + nút "Xác thực ngay" (kèm lưu ý phụ huynh nếu `pending`);
 * - vừa xác thực xong (cờ một lần) → thông báo thành công.
 */
export function AccountBanner() {
  const { state } = useAuth();
  const [verifiedFlash, setVerifiedFlash] = useState(false);

  useEffect(() => {
    // Đọc sessionStorage chỉ làm được sau hydrate → setState trong effect là chủ đích.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    if (consumeAccountFlash() === "verified") setVerifiedFlash(true); // Strict Mode: lần 2 đọc null, không ghi đè.
  }, []);

  if (state.status !== "user") return null;
  const { user } = state;

  if (!user.is_verified) {
    return (
      <Alert variant="warning" title="Cần xác thực tài khoản" className="mb-6">
        <p>
          {user.parent_consent_status === "pending"
            ? "Vui lòng xác thực tài khoản. Chúng tôi cũng đã gửi email xác nhận tới phụ huynh của bạn — bạn cần cả 2 bước hoàn tất mới mua được khóa học."
            : "Vui lòng xác thực tài khoản để có thể mua khóa học."}
        </p>
        <Link
          href="/xac-thuc-otp"
          className="mt-3 inline-flex h-11 items-center rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700"
        >
          Xác thực ngay
        </Link>
      </Alert>
    );
  }

  if (verifiedFlash) {
    return (
      <Alert variant="success" className="mb-6" dismissible>
        Xác thực tài khoản thành công
      </Alert>
    );
  }
  return null;
}
