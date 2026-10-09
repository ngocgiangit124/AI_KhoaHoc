"use client";

import { useRef, useState } from "react";
import { Button, ConfirmDialog, formatPrice, useToast, type ButtonVariant } from "@vitaminvui/ui/v2";
import { cancelOrder } from "@/lib/orders/api";
import { classifyCancelError } from "@/lib/orders/errors";
import type { OrderDetail } from "@/lib/orders/schemas";

export type CancelResult =
  | { type: "cancelled"; order: OrderDetail }
  /** 409: đơn vừa đổi trạng thái -> cha tải lại đơn và hiện thông báo; KHÔNG thử lại. */
  | { type: "conflict"; message: string }
  | { type: "not_found" };

/**
 * Học sinh tự huỷ đơn đang chờ (US-022 BR14, AC12): luôn có hộp xác nhận; nhắc "đã chuyển khoản thì đừng huỷ".
 * Chặn bấm kép bằng ref; lỗi khác 409 hiện trong hộp (giữ hộp mở để thử lại).
 */
export function CancelOrderButton({
  code,
  total,
  onResult,
  variant = "secondary",
  block,
}: {
  code: string;
  total: number;
  onResult: (r: CancelResult) => void;
  variant?: ButtonVariant;
  block?: boolean;
}) {
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const lock = useRef(false);

  async function confirm() {
    if (lock.current) return;
    lock.current = true;
    setBusy(true);
    setError(null);
    try {
      const order = await cancelOrder(code);
      setOpen(false);
      toast.show({ tone: "success", title: "Đã huỷ đơn", description: `Đơn ${code}` });
      onResult({ type: "cancelled", order });
    } catch (err) {
      const f = classifyCancelError(err);
      if (f.kind === "conflict") {
        setOpen(false);
        onResult({ type: "conflict", message: f.message });
      } else if (f.kind === "not_found") {
        setOpen(false);
        onResult({ type: "not_found" });
      } else {
        setError(f.message);
      }
    } finally {
      lock.current = false;
      setBusy(false);
    }
  }

  return (
    <>
      <Button
        variant={variant}
        size="lg"
        block={block}
        onClick={() => {
          setError(null);
          setOpen(true);
        }}
      >
        Huỷ đơn
      </Button>
      <ConfirmDialog
        open={open}
        onClose={() => (busy ? undefined : setOpen(false))}
        tone="danger"
        title={`Huỷ đơn ${code}?`}
        description={`Đơn ${formatPrice(total)} sẽ bị huỷ và không thể khôi phục. Các khóa vẫn còn trong giỏ hàng nếu bạn muốn đặt lại.`}
        confirmLabel="Huỷ đơn"
        cancelLabel="Không huỷ"
        loading={busy}
        loadingText="Đang huỷ…"
        onConfirm={() => void confirm()}
      >
        <div className="flex flex-col gap-3">
          <p className="rounded-control bg-warning-soft p-3 text-sm text-ink">
            <span className="font-semibold">Bạn đã chuyển khoản cho đơn này?</span> Đừng huỷ. Hãy liên hệ Quản trị viên để được xác nhận.
          </p>
          {error ? (
            <p role="alert" className="text-sm font-medium text-danger">
              {error}
            </p>
          ) : null}
        </div>
      </ConfirmDialog>
    </>
  );
}
