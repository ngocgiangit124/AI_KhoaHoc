"use client";

import { useEffect, useState } from "react";
import { Alert } from "@vitaminvui/ui";
import { consumeRegisterFlash, type RegisterFlash } from "@/lib/auth/flash";

/**
 * Banner sau đăng ký thành công (design US-001 §3). Cờ do RegisterForm ghi một lần
 * (`lib/auth/flash.ts`) và bị xoá ngay khi đọc, nên F5 không hiện lại.
 * Nút "Xác thực ngay" → /xac-thuc-otp thuộc T04/FW1 phần 2.
 */
export function RegisterSuccessBanner() {
  const [kind, setKind] = useState<RegisterFlash | null>(null);

  useEffect(() => {
    const flash = consumeRegisterFlash();
    // Đọc sessionStorage (hệ thống ngoài) chỉ làm được sau hydrate → setState trong effect là chủ đích.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    if (flash) setKind(flash); // Strict Mode chạy effect 2 lần: lần 2 đọc null, không ghi đè.
  }, []);

  if (kind === "ok") {
    return <Alert variant="info" className="mb-6">Vui lòng xác thực tài khoản để có thể mua khóa học</Alert>;
  }
  if (kind === "parent_pending") {
    return (
      <Alert variant="info" className="mb-6">
        Vui lòng xác thực tài khoản. Chúng tôi cũng đã gửi email xác nhận tới phụ huynh của bạn — bạn cần cả 2 bước
        hoàn tất mới mua được khóa học.
      </Alert>
    );
  }
  return null;
}
