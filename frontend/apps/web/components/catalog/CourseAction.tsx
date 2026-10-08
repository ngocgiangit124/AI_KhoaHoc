"use client";

import type { ReactNode } from "react";
import { Button, ButtonLink, IconCheckCircle, IconHourglass, IconInfo, Skeleton, formatPrice } from "@vitaminvui/ui/v2";
import { useCourseCta } from "./CourseCtaProvider";

function StatusBox({ tone, icon, title, children }: { tone: "warning" | "info"; icon: ReactNode; title: string; children: ReactNode }) {
  const box = { warning: "bg-warning-soft", info: "bg-info-soft" }[tone];
  const ink = { warning: "text-warning", info: "text-info" }[tone];
  return (
    <div className={`flex gap-3 rounded-card p-4 ${box}`} role="status">
      <span className={`mt-0.5 ${ink}`}>{icon}</span>
      <div className="flex flex-col gap-1">
        <p className="font-semibold text-ink">{title}</p>
        <p className="text-sm text-ink">{children}</p>
      </div>
    </div>
  );
}

/**
 * Hành động chính theo `viewer_state` x `paid_checkout_enabled` (design-system-v2 §12.2; logic ở `lib/catalog/cta.ts`).
 * `compact`: bản rút gọn cho thanh dính đáy trên mobile. Khóa có phí khi thanh toán tạm khoá: giá + "Sắp mở bán", KHÔNG có nút mua.
 */
export function CourseAction({ compact = false }: { compact?: boolean }) {
  const { model, error, run, loginHref } = useCourseCta();

  let content: ReactNode;
  switch (model.kind) {
    case "skeleton":
      content = <Skeleton className="h-13 w-full" />;
      break;
    case "owned":
      content = (
        <div className="flex flex-col gap-2">
          <ButtonLink href={model.href} size="lg" block>
            {model.label}
          </ButtonLink>
          {!compact ? (
            <p className="flex items-center gap-1.5 text-sm text-success">
              <IconCheckCircle size={16} />
              Bạn đã sở hữu khóa học này.
            </p>
          ) : null}
        </div>
      );
      break;
    case "pending":
      content = compact ? (
        <p className="flex items-center gap-2 font-semibold text-warning" role="status">
          <IconHourglass size={18} />
          {model.label}
        </p>
      ) : (
        <StatusBox tone="warning" icon={<IconHourglass />} title={model.label}>
          Bạn đã gửi yêu cầu học khóa này. Khi giáo viên duyệt, bạn sẽ nhận email và vào học được ngay.
        </StatusBox>
      );
      break;
    case "coming_soon":
      content = compact ? (
        <p className="font-semibold text-info">Sắp mở bán</p>
      ) : (
        <StatusBox tone="info" icon={<IconInfo />} title="Sắp mở bán">
          Thanh toán trực tuyến đang tạm đóng. Khóa học này sẽ mở bán khi thanh toán hoạt động trở lại.
        </StatusBox>
      );
      break;
    case "login":
      content = (
        <div className="flex flex-col gap-2">
          <ButtonLink href={loginHref} size="lg" block>
            {model.label}
          </ButtonLink>
          {!compact && model.label.startsWith("Đăng ký") ? (
            <p className="text-sm text-ink-soft">Khóa miễn phí cần giáo viên duyệt. Bạn sẽ nhận email khi được duyệt.</p>
          ) : null}
        </div>
      );
      break;
    case "register_free":
      content = (
        <div className="flex flex-col gap-2">
          <Button size="lg" block loading={model.busy} loadingText="Đang gửi yêu cầu…" onClick={run}>
            {model.label}
          </Button>
          {!compact ? <p className="text-sm text-ink-soft">Khóa miễn phí cần giáo viên duyệt. Bạn sẽ nhận email khi được duyệt.</p> : null}
        </div>
      );
      break;
    case "buy_waiting":
    case "in_cart":
      // Giỏ hàng/thanh toán thuộc FW3 — nút chờ, chưa hoạt động, luôn kèm chữ giải thích (design §11.2 "Disabled").
      content = (
        <div className="flex flex-col gap-2">
          <Button size="lg" block disabled>
            {model.label}
          </Button>
          {!compact ? <p className="text-sm text-ink-soft">Giỏ hàng sắp mở.</p> : null}
        </div>
      );
      break;
    case "retry":
      content = (
        <div className="flex flex-col gap-2">
          <Button size="lg" block variant="secondary" onClick={run}>
            {model.label}
          </Button>
          <p role="alert" className="text-sm font-medium text-danger">
            Không xác định được trạng thái khóa học của bạn.
          </p>
        </div>
      );
      break;
  }

  return (
    <div className="flex flex-col gap-2">
      {content}
      {error ? (
        <p role="alert" className="text-sm font-medium text-danger">
          {error}
        </p>
      ) : null}
    </div>
  );
}

/** Giá lớn của khóa (`price` nguyên VNĐ; 0 = Miễn phí). */
export function PriceLine({ price, isFree, size = "lg" }: { price: number; isFree: boolean; size?: "lg" | "md" }) {
  return (
    <p className={`num font-extrabold ${size === "lg" ? "text-title" : "text-lg"} ${isFree ? "text-success" : "text-ink"}`}>
      {isFree ? "Miễn phí" : formatPrice(price)}
    </p>
  );
}
