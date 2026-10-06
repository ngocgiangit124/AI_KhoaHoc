"use client";

import { useState } from "react";
import { Button, useToast } from "@vitaminvui/ui/v2";

/**
 * "Đăng ký học miễn phí" → POST /courses/{course}/free-enrollments (201 pending_approval).
 * TODO(dev): gọi API; 403 ACCOUNT_NOT_VERIFIED → màn "Cần xác thực tài khoản"; 409 ENROLLMENT_PENDING/ALREADY_OWNED → làm mới viewer-state.
 */
export function RegisterFreeButton({ block = true }: { block?: boolean }) {
  const [loading, setLoading] = useState(false);
  const toast = useToast();
  return (
    <Button
      size="lg"
      block={block}
      loading={loading}
      loadingText="Đang gửi yêu cầu…"
      onClick={() => {
        setLoading(true);
        setTimeout(() => {
          setLoading(false);
          toast.show({ tone: "success", title: "Đã gửi yêu cầu đăng ký", description: "Bạn sẽ nhận email khi giáo viên duyệt." });
        }, 900);
      }}
    >
      Đăng ký học miễn phí
    </Button>
  );
}
