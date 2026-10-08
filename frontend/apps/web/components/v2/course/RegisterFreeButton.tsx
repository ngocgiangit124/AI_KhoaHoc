"use client";

import { useState } from "react";
import { Button, useToast } from "@vitaminvui/ui/v2";
import { AccountGateDialog, type GateKind } from "@/components/v2/auth/AccountGate";

/**
 * "Đăng ký học miễn phí" → POST /courses/{course}/free-enrollments (201 pending_approval).
 * 403 `ACCOUNT_NOT_VERIFIED` → hộp thoại "Xác thực email để tiếp tục" (giữ nguyên trang khóa học phía sau).
 * TODO(dev): gọi API; 409 ENROLLMENT_PENDING/ALREADY_OWNED → làm mới viewer-state.
 * `gate` chỉ để bản xem trước giả lập 403.
 */
export function RegisterFreeButton({ block = true, gate }: { block?: boolean; gate?: GateKind }) {
  const [loading, setLoading] = useState(false);
  const [blocked, setBlocked] = useState(false);
  const toast = useToast();
  return (
    <>
      <Button
        size="lg"
        block={block}
        loading={loading}
        loadingText="Đang gửi yêu cầu…"
        onClick={() => {
          setLoading(true);
          setTimeout(() => {
            setLoading(false);
            if (gate) {
              setBlocked(true);
              return;
            }
            toast.show({ tone: "success", title: "Đã gửi yêu cầu đăng ký", description: "Bạn sẽ nhận email khi giáo viên duyệt." });
          }, 700);
        }}
      >
        Đăng ký học miễn phí
      </Button>
      {gate ? <AccountGateDialog kind={gate} open={blocked} onClose={() => setBlocked(false)} /> : null}
    </>
  );
}
