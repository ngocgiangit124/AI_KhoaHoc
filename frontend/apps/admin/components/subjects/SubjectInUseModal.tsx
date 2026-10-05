"use client";

import { useState } from "react";
import { Button, Modal } from "@vitaminvui/ui";
import type { Subject } from "@/lib/subjects/types";

export interface SubjectInUseModalProps {
  subject: Subject;
  /** Số khóa đang gán; `null` khi chỉ biết qua 409 (số liệu cũ). */
  count: number | null;
  onClose: () => void;
  onHide: () => Promise<void>;
}

/** Chặn xoá chuyên đề đang gán khóa học (US-011 AC3): chỉ "Đã hiểu" + gợi ý Ẩn, không có nút xác nhận xoá. */
export function SubjectInUseModal({ subject, count, onClose, onHide }: SubjectInUseModalProps) {
  const [pending, setPending] = useState(false);
  const canHide = subject.status === "active";
  return (
    <Modal
      title="Không thể xoá chuyên đề"
      onClose={onClose}
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={pending}>
            Đã hiểu
          </Button>
          {canHide ? (
            <Button
              loading={pending}
              onClick={async () => {
                setPending(true);
                try {
                  await onHide();
                } finally {
                  setPending(false);
                }
              }}
            >
              Ẩn chuyên đề này
            </Button>
          ) : null}
        </>
      }
    >
      <p>
        Chuyên đề <strong className="break-words">{subject.name}</strong> đang được gán cho{" "}
        {count !== null ? `${count} khóa học` : "ít nhất 1 khóa học"} nên không thể xoá.
        {canHide ? " Bạn có thể chuyển sang trạng thái Ẩn thay thế." : " Chuyên đề này đã ở trạng thái Ẩn."}
      </p>
    </Modal>
  );
}
