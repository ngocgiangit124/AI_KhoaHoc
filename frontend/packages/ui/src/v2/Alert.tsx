import type { ReactNode } from "react";
import { cx } from "./cx";
import { IconAlertCircle, IconAlertTriangle, IconCheckCircle, IconInfo } from "./icons";

export type AlertTone = "info" | "success" | "warning" | "danger";

export interface AlertProps {
  tone?: AlertTone;
  title?: ReactNode;
  children?: ReactNode;
  /** Nút/liên kết hành động, ví dụ "Tải lại". */
  action?: ReactNode;
  className?: string;
  /** `alert` cho lỗi xuất hiện sau thao tác (đọc ngay); `status` cho thông tin. Mặc định theo tone. */
  role?: "alert" | "status" | "none";
}

const TONES: Record<AlertTone, { box: string; icon: ReactNode }> = {
  info: { box: "border-info/30 bg-info-soft", icon: <IconInfo className="text-info" /> },
  success: { box: "border-success/30 bg-success-soft", icon: <IconCheckCircle className="text-success" /> },
  warning: { box: "border-warning/30 bg-warning-soft", icon: <IconAlertTriangle className="text-warning" /> },
  danger: { box: "border-danger/30 bg-danger-soft", icon: <IconAlertCircle className="text-danger" /> },
};

/** Thông báo nằm trong trang (banner). Chữ dùng `ink` trên nền nhạt để đọc dễ; icon mang màu trạng thái. */
export function Alert({ tone = "info", title, children, action, className, role }: AlertProps) {
  const resolvedRole = role ?? (tone === "danger" ? "alert" : "status");
  return (
    <div
      role={resolvedRole === "none" ? undefined : resolvedRole}
      className={cx("flex flex-col gap-3 rounded-card border p-4 sm:flex-row sm:items-start", TONES[tone].box, className)}
    >
      <div className="flex flex-1 items-start gap-3">
        <span className="mt-0.5">{TONES[tone].icon}</span>
        <div className="flex flex-col gap-1 text-base text-ink">
          {title ? <p className="font-semibold">{title}</p> : null}
          {children ? <div className="text-sm leading-relaxed text-ink">{children}</div> : null}
        </div>
      </div>
      {action ? <div className="shrink-0 pl-8 sm:pl-0">{action}</div> : null}
    </div>
  );
}
