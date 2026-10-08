"use client";

import { useState } from "react";
import { Alert, Button, Dialog, IconCheck, IconCopy } from "@vitaminvui/ui/v2";
import { STAFF_PASSWORD_MIN_LENGTH } from "@/lib/auth/schemas";

export interface PasswordRevealDialogProps {
  name: string;
  password: string;
  /** Vừa tạo mới hay vừa đặt lại: chỉ đổi lời dẫn. */
  kind: "create" | "reset";
  onClose: () => void;
}

/**
 * Hộp "Mật khẩu khởi tạo" (US-016 §2.5): hiện MỘT lần. Không đóng bằng Esc/bấm nền; mật khẩu chỉ nằm trong state của màn hình,
 * xoá ngay khi đóng. Không ghi log, không lưu storage.
 */
export function PasswordRevealDialog({ name, password, kind, onClose }: PasswordRevealDialogProps) {
  const [copied, setCopied] = useState<"idle" | "ok" | "fail">("idle");

  async function copy() {
    try {
      await navigator.clipboard.writeText(password);
      setCopied("ok");
    } catch {
      setCopied("fail");
    }
  }

  return (
    <Dialog
      open
      size="md"
      dismissible={false}
      onClose={onClose}
      title={kind === "create" ? "Mật khẩu khởi tạo" : "Mật khẩu mới"}
      description={kind === "create" ? `Tài khoản của ${name} đã được tạo.` : `Đã đặt lại mật khẩu cho ${name}.`}
      footer={
        <Button onClick={onClose} className="max-sm:h-11">
          Đã lưu mật khẩu, đóng
        </Button>
      }
    >
      <div className="flex flex-col gap-4">
        <Alert tone="warning" title="Chỉ hiển thị một lần">
          Mật khẩu này chỉ hiển thị 1 lần và không được lưu lại. Vui lòng gửi cho {name} qua kênh an toàn; họ phải đổi mật khẩu ngay khi đăng nhập lần đầu (mật khẩu mới tối thiểu {STAFF_PASSWORD_MIN_LENGTH} ký tự).
        </Alert>
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
          <code
            data-testid="initial-password"
            className="min-w-0 flex-1 select-all break-all rounded-control border border-line bg-sunken px-3 py-2.5 font-mono text-base font-semibold text-ink"
          >
            {password}
          </code>
          <Button
            variant="secondary"
            className="max-sm:h-11"
            leadingIcon={copied === "ok" ? <IconCheck size={16} /> : <IconCopy size={16} />}
            onClick={() => void copy()}
          >
            {copied === "ok" ? "Đã sao chép" : "Sao chép"}
          </Button>
        </div>
        <p role="status" className="min-h-5 text-sm text-ink-soft">
          {copied === "ok" ? "Đã sao chép vào bộ nhớ tạm." : copied === "fail" ? "Không sao chép tự động được, hãy chọn mật khẩu và sao chép thủ công." : ""}
        </p>
      </div>
    </Dialog>
  );
}
