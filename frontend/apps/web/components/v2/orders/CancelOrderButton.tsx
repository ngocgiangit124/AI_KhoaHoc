"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { Button, ConfirmDialog, formatPrice, type ButtonVariant } from "@vitaminvui/ui/v2";

/**
 * Học sinh tự huỷ đơn đang chờ (US-022 BR14, AC12): luôn có hộp xác nhận; nhắc "đã chuyển khoản thì đừng huỷ".
 * Bản xem trước chuyển sang `resultHref` (biến thể đã huỷ hoặc 409).
 * TODO(dev): POST /orders/{code}/cancel → 200: router.refresh() + toast "Đã huỷ đơn";
 * 409 (đơn vừa được duyệt/huỷ/hết hạn) → router.refresh() + Alert với trạng thái mới; không thử lại.
 */
export function CancelOrderButton({
  code,
  total,
  resultHref,
  variant = "secondary",
  block,
}: {
  code: string;
  total: number;
  resultHref: string;
  variant?: ButtonVariant;
  block?: boolean;
}) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  return (
    <>
      <Button variant={variant} size="lg" block={block} onClick={() => setOpen(true)}>
        Huỷ đơn
      </Button>
      <ConfirmDialog
        open={open}
        onClose={() => setOpen(false)}
        tone="danger"
        title={`Huỷ đơn ${code}?`}
        description={`Đơn ${formatPrice(total)} sẽ bị huỷ và không thể khôi phục. Các khóa vẫn còn trong giỏ hàng nếu bạn muốn đặt lại.`}
        confirmLabel="Huỷ đơn"
        cancelLabel="Không huỷ"
        loading={busy}
        loadingText="Đang huỷ…"
        onConfirm={() => {
          setBusy(true);
          setTimeout(() => router.push(resultHref, { scroll: false }), 700);
        }}
      >
        <p className="rounded-control bg-warning-soft p-3 text-sm text-ink">
          <span className="font-semibold">Bạn đã chuyển khoản cho đơn này?</span> Đừng huỷ. Hãy liên hệ Quản trị viên để được xác nhận.
        </p>
      </ConfirmDialog>
    </>
  );
}
