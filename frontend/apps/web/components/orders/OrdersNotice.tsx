"use client";

import { Alert, Button, ButtonLink } from "@vitaminvui/ui/v2";
import type { OrderLoadFailure } from "@/lib/orders/errors";
import { routes } from "@/lib/routes";

/** Thông báo lỗi tải trong khung trang (header/footer vẫn còn). `session`: hộp thoại phiên đã hiện, ở đây chỉ báo ngắn. */
export function OrdersNotice({ kind, onRetry, what }: { kind: OrderLoadFailure; onRetry: () => void; what: "cart" | "checkout" | "orders" | "order" }) {
  const retry = (
    <Button variant="secondary" onClick={onRetry}>
      Thử lại
    </Button>
  );
  switch (kind) {
    case "session":
      return (
        <Alert tone="warning" title="Phiên đăng nhập đã kết thúc">
          Hãy đăng nhập lại để tiếp tục.
        </Alert>
      );
    case "not_verified":
      return (
        <Alert tone="info" title="Cần xác thực tài khoản" action={<ButtonLink href={routes.verifyOtp} variant="secondary">Xác thực ngay</ButtonLink>}>
          Bạn cần xác thực email trước khi đặt mua khóa học.
        </Alert>
      );
    case "forbidden":
      return (
        <Alert tone="warning" title="Tài khoản này không dùng được mục này">
          Giỏ hàng và đơn hàng chỉ dành cho tài khoản học sinh.
        </Alert>
      );
    case "not_found":
      return (
        <Alert tone="danger" title="Không tìm thấy đơn hàng" action={<ButtonLink href={routes.myOrders} variant="secondary">Đơn hàng của tôi</ButtonLink>}>
          Mã đơn không đúng hoặc đơn không thuộc tài khoản của bạn.
        </Alert>
      );
    case "throttled":
      return (
        <Alert tone="warning" title="Bạn thao tác hơi nhanh" action={retry}>
          Đợi một chút rồi thử lại.
        </Alert>
      );
    default: {
      const title = {
        cart: "Không tải được giỏ hàng",
        checkout: "Không tải được trang thanh toán",
        orders: "Không tải được danh sách đơn hàng",
        order: "Không tải được đơn hàng",
      }[what];
      return (
        <Alert tone="danger" title={title} action={retry}>
          Kiểm tra kết nối mạng rồi thử lại.{what === "cart" ? " Các khóa bạn đã thêm vẫn được giữ." : ""}
        </Alert>
      );
    }
  }
}
