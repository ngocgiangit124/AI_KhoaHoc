import { Badge, type BadgeTone } from "@vitaminvui/ui/v2";
import { COUPON_STATE_LABELS, type CouponState } from "@/lib/coupons/types";

const TONE: Record<CouponState, BadgeTone> = { active: "success", upcoming: "info", expired: "neutral", exhausted: "warning", inactive: "neutral" };

/** Trạng thái suy ra của mã: luôn kèm chữ (không chỉ màu). */
export function CouponStateBadge({ state }: { state: CouponState }) {
  return (
    <Badge size="sm" dot tone={TONE[state]}>
      {COUPON_STATE_LABELS[state]}
    </Badge>
  );
}
