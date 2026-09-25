"use client";

import { useState } from "react";
import { Button, type ButtonVariant } from "./Button";
import { Modal } from "./Modal";

export interface ConfirmModalProps {
  title: string;
  description: string;
  confirmLabel?: string;
  confirmVariant?: ButtonVariant;
  /** Bắt nhập lý do trước khi xác nhận được (hoàn tiền, xuất kèm liên hệ...). */
  requireReason?: boolean;
  onConfirm: (reason?: string) => void | Promise<void>;
  onClose: () => void;
}

/**
 * Hộp xác nhận hành động nguy hiểm (xoá, huỷ, hoàn tiền, khoá tài khoản — design-system.md
 * §1, §5.1). Luôn nêu rõ hậu quả không thể hoàn tác qua `description`.
 */
export function ConfirmModal({
  title,
  description,
  confirmLabel = "Xác nhận",
  confirmVariant = "danger",
  requireReason = false,
  onConfirm,
  onClose,
}: ConfirmModalProps) {
  const [reason, setReason] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const canConfirm = !requireReason || reason.trim().length > 0;

  async function handleConfirm() {
    setSubmitting(true);
    try {
      await onConfirm(requireReason ? reason.trim() : undefined);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Huỷ
          </Button>
          <Button
            variant={confirmVariant}
            onClick={handleConfirm}
            loading={submitting}
            disabled={!canConfirm}
          >
            {confirmLabel}
          </Button>
        </>
      }
    >
      <p>{description}</p>
      {requireReason ? (
        <label className="mt-4 block text-sm">
          <span className="mb-1 block font-medium text-gray-900">
            Lý do <span className="text-rose-600">*</span>
          </span>
          <textarea
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            rows={3}
            className="w-full rounded-lg border border-gray-300 p-2 text-sm focus:border-indigo-600 focus:outline-none"
          />
        </label>
      ) : null}
    </Modal>
  );
}
