import { IconCart } from "@vitaminvui/ui/v2";
import { InfoPage } from "@/components/v2/info/InfoPage";

export const dynamic = "force-dynamic";

/** Giỏ hàng/checkout thuộc V2 (FW3). Bản xem trước chỉ có trang giữ chỗ để biến thể "thanh toán bật" không dẫn tới 404. */
export default function CartPlaceholder() {
  return (
    <InfoPage
      loggedIn
      icon={<IconCart size={32} />}
      title="Giỏ hàng mở cùng thanh toán trực tuyến"
      description="Thanh toán trực tuyến đang tạm đóng (V2). Màn giỏ hàng và thanh toán sẽ được thiết kế trong đợt đó. Bạn vẫn đăng ký được các khóa miễn phí."
    />
  );
}
