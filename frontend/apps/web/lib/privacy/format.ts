import { formatDate, formatDateTime } from "@vitaminvui/ui/v2";
import type { ParentContact } from "./schemas";

const CONSENT_LABELS: Record<string, string> = {
  terms: "Điều khoản sử dụng",
  privacy_policy: "Chính sách xử lý dữ liệu cá nhân",
  marketing: "Nhận thông tin khuyến mại",
  parent_consent: "Xác nhận của phụ huynh",
};

/** Nhãn loại đồng ý; loại lạ -> nguyên giá trị (không vỡ trang khi backend thêm loại mới). */
export function consentLabel(type: string): string {
  return CONSENT_LABELS[type] ?? type;
}

const CHANNEL_LABELS: Record<string, string> = {
  web_form: "Trên website",
  email_otp: "Qua mã gửi email",
  email_link: "Qua liên kết trong thư",
};

export function consentChannelLabel(channel: string): string {
  return CHANNEL_LABELS[channel] ?? channel;
}

export type ParentNoticeStatus = "none" | "enabled" | "opted-out" | "paused";

/**
 * Trạng thái thông báo cho phụ huynh: chưa có email · đang nhận · phụ huynh đã ngừng (có `notices_opted_out_at`) ·
 * tạm dừng (có email, chưa ngừng nhưng `notices_enabled=false` = tính năng đang tắt phía hệ thống).
 */
export function parentNoticeStatus(c: ParentContact): ParentNoticeStatus {
  if (!c.has_email) return "none";
  if (c.notices_enabled) return "enabled";
  return c.notices_opted_out_at ? "opted-out" : "paused";
}

export const PARENT_NOTICE_STATUS_LABEL: Record<ParentNoticeStatus, string> = {
  none: "Chưa có email phụ huynh",
  enabled: "Đang nhận thông báo",
  "opted-out": "Phụ huynh đã ngừng nhận",
  paused: "Tạm thời chưa gửi thông báo",
};

/** `2026-10-09T00:00:00+07:00` -> "00:00, 09/10/2026" (giờ VN). Không đọc được -> chuỗi rỗng. */
export function formatVnDateTime(iso: string | null | undefined): string {
  if (!iso || Number.isNaN(Date.parse(iso))) return "";
  return formatDateTime(iso);
}

export function formatVnDate(iso: string | null | undefined): string {
  if (!iso || Number.isNaN(Date.parse(iso))) return "";
  return formatDate(iso);
}
