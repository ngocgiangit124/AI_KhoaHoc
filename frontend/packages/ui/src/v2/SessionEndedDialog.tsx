"use client";

import { ButtonLink } from "./Button";
import { Dialog } from "./Dialog";
import { IconInfo, IconMonitorSmartphone } from "./icons";

export type SessionEndedReason = "replaced" | "revoked";

export interface SessionEndedDialogProps {
  open: boolean;
  /**
   * `replaced` = 401 `SESSION_REPLACED` (tài khoản vừa đăng nhập ở thiết bị khác, US-014).
   * `revoked`  = 401 `SESSION_REVOKED` (mật khẩu vừa đổi/đặt lại, hoặc email vừa đổi — US-015, Bảo mật cụm 1).
   */
  reason: SessionEndedReason;
  /** Trang đăng nhập, nên kèm `?next=` đường dẫn hiện tại (đã qua safeRedirect). */
  loginHref: string;
  /** Trang đổi mật khẩu/quên mật khẩu — chỉ hiện ở `replaced` ("Không phải bạn?"). */
  forgotHref?: string;
  /** Đang ở màn học/quiz: thêm câu trấn an "tiến độ/bài làm đã được lưu". */
  context?: "lesson" | "quiz" | "page";
}

const COPY: Record<SessionEndedReason, { title: string; body: string }> = {
  replaced: {
    title: "Tài khoản vừa đăng nhập trên thiết bị khác",
    body: "Mỗi tài khoản học sinh chỉ học trên một thiết bị cùng lúc, nên thiết bị này đã được đăng xuất.",
  },
  revoked: {
    title: "Bạn cần đăng nhập lại",
    body: "Mật khẩu hoặc email của tài khoản vừa được thay đổi, nên các thiết bị khác đã được đăng xuất để bảo vệ tài khoản.",
  },
};

const SAVED: Record<NonNullable<SessionEndedDialogProps["context"]>, string | null> = {
  lesson: "Tiến độ xem video đã được lưu. Đăng nhập lại để học tiếp đúng chỗ.",
  quiz: "Các câu bạn đã chọn đã được lưu. Đăng nhập lại để làm tiếp (nếu bài chưa hết giờ).",
  page: null,
};

/**
 * Hộp thoại chặn khi phiên học sinh hết hiệu lực thật (US-014 §2.1). KHÔNG đóng được (không X,
 * không Esc, không bấm nền) vì phiên đã mất — chỉ có đường đi tiếp là đăng nhập lại.
 * - `replaced`: icon thiết bị, nền `warning-soft` — có liên kết "Không phải bạn? Đặt lại mật khẩu".
 * - `revoked`: icon thông tin, nền `info-soft` — giọng bình thường, không gây hoang mang.
 * Phần nghe sự kiện `forced-logout` từ api-client là việc của dev (xem ForcedLogoutOverlay v1).
 */
export function SessionEndedDialog({ open, reason, loginHref, forgotHref, context = "page" }: SessionEndedDialogProps) {
  const copy = COPY[reason];
  const saved = SAVED[context];
  return (
    <Dialog
      open={open}
      onClose={() => {}}
      dismissible={false}
      size="sm"
      title={
        <span className="flex flex-col gap-3">
          <span
            aria-hidden="true"
            className={
              reason === "replaced"
                ? "flex size-12 items-center justify-center rounded-full bg-warning-soft text-warning"
                : "flex size-12 items-center justify-center rounded-full bg-info-soft text-info"
            }
          >
            {reason === "replaced" ? <IconMonitorSmartphone size={24} /> : <IconInfo size={24} />}
          </span>
          {copy.title}
        </span>
      }
      description={
        <span className="flex flex-col gap-2">
          <span>{copy.body}</span>
          {saved ? <span className="text-ink">{saved}</span> : null}
        </span>
      }
      footer={
        <div className="flex w-full flex-col gap-3">
          <ButtonLink href={loginHref} size="lg" block>
            Đăng nhập lại
          </ButtonLink>
          {reason === "replaced" && forgotHref ? (
            <p className="text-center text-sm text-ink-soft">
              Không phải bạn?{" "}
              <a href={forgotHref} className="focus-ring rounded font-semibold text-primary underline underline-offset-4">
                Đặt lại mật khẩu
              </a>
            </p>
          ) : null}
        </div>
      }
    />
  );
}
