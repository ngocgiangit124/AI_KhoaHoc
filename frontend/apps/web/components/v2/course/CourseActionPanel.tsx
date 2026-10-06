import type { ReactNode } from "react";
import { ButtonLink, IconCheckCircle, IconHourglass, IconInfo, IconPlay, formatPrice } from "@vitaminvui/ui/v2";
import type { CourseDetail, ViewerState } from "@/lib/mock/v2/types";
import { routes } from "@/lib/v2/routes";
import { RegisterFreeButton } from "./RegisterFreeButton";

export type Viewer = ViewerState | "guest";

function StatusBox({ tone, icon, title, children }: { tone: "warning" | "info" | "success"; icon: ReactNode; title: string; children: ReactNode }) {
  const box = { warning: "bg-warning-soft", info: "bg-info-soft", success: "bg-success-soft" }[tone];
  const ink = { warning: "text-warning", info: "text-info", success: "text-success" }[tone];
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
 * Khối giá + hành động chính theo `viewer_state` (GET /courses/{slug}/viewer-state) và
 * `paid_checkout_enabled` (GET /config/public). Bảng quyết định ở design-system-v2 §12.2.
 * `compact`: bản rút gọn cho thanh dính đáy trên mobile.
 */
export function CourseAction({
  course,
  viewer,
  paidEnabled,
  resumeLessonId,
  compact = false,
}: {
  course: CourseDetail;
  viewer: Viewer;
  paidEnabled: boolean;
  resumeLessonId: number | null;
  compact?: boolean;
}) {
  const loginNext = `${routes.login}?next=${encodeURIComponent(routes.course(course.slug))}`;
  const previewLink = course.has_preview ? (
    <ButtonLink href="#hoc-thu" variant="secondary" size="lg" block leadingIcon={<IconPlay size={16} />}>
      Học thử bài miễn phí
    </ButtonLink>
  ) : null;

  if (viewer === "owned") {
    return (
      <div className="flex flex-col gap-2">
        <ButtonLink href={routes.lesson(course.id, resumeLessonId ?? course.outline[0]?.lessons[0]?.id ?? 0)} size="lg" block>
          Tiếp tục học
        </ButtonLink>
        {!compact ? (
          <p className="flex items-center gap-1.5 text-sm text-success">
            <IconCheckCircle size={16} />
            Bạn đã sở hữu khóa học này.
          </p>
        ) : null}
      </div>
    );
  }

  if (course.is_free) {
    if (viewer === "pending_approval") {
      return compact ? (
        <p className="flex items-center gap-2 font-semibold text-warning">
          <IconHourglass size={18} />
          Đang chờ giáo viên duyệt
        </p>
      ) : (
        <StatusBox tone="warning" icon={<IconHourglass />} title="Đang chờ duyệt">
          Bạn đã gửi yêu cầu học khóa này. Khi giáo viên duyệt, bạn sẽ nhận email và vào học được ngay.
        </StatusBox>
      );
    }
    return (
      <div className="flex flex-col gap-2">
        {viewer === "guest" ? (
          <ButtonLink href={loginNext} size="lg" block>
            Đăng ký học miễn phí
          </ButtonLink>
        ) : (
          <RegisterFreeButton />
        )}
        {!compact ? <p className="text-sm text-ink-soft">Khóa miễn phí cần giáo viên duyệt. Bạn sẽ nhận email khi được duyệt.</p> : null}
      </div>
    );
  }

  // Khóa có phí, thanh toán đang tạm khoá: không có nút mua (không để học sinh đi vào ngõ cụt).
  if (!paidEnabled) {
    return compact ? (
      previewLink ?? <p className="text-sm text-ink-soft">Sắp mở bán</p>
    ) : (
      <div className="flex flex-col gap-3">
        <StatusBox tone="info" icon={<IconInfo />} title="Sắp mở bán">
          Thanh toán trực tuyến đang tạm đóng. Khóa học này sẽ mở bán khi thanh toán hoạt động trở lại.
        </StatusBox>
        {previewLink}
      </div>
    );
  }

  if (viewer === "in_cart") {
    return (
      <div className="flex flex-col gap-2">
        <ButtonLink href={routes.cart} size="lg" block>
          Xem giỏ hàng
        </ButtonLink>
        {!compact ? <p className="text-sm text-ink-soft">Khóa học đã có trong giỏ hàng của bạn.</p> : null}
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-2">
      <ButtonLink href={viewer === "guest" ? loginNext : routes.cart} size="lg" block>
        Mua khóa học
      </ButtonLink>
      {!compact && course.has_preview ? previewLink : null}
    </div>
  );
}

export function PriceLine({ course, size = "lg" }: { course: CourseDetail; size?: "lg" | "md" }) {
  return (
    <p className={`num font-extrabold ${size === "lg" ? "text-title" : "text-lg"} ${course.is_free ? "text-success" : "text-ink"}`}>
      {formatPrice(course.price)}
    </p>
  );
}
