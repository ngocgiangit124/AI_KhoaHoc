"use client";

import { Alert, Button, ButtonLink } from "@vitaminvui/ui/v2";
import type { MyLoadFailure } from "@/lib/my/errors";
import { routes } from "@/lib/routes";

/** Thông báo lỗi tải trong khung trang (header/footer vẫn còn). `session`: hộp thoại phiên đã hiện, ở đây chỉ báo ngắn. */
export function MyNotice({ kind, courseSlug, onRetry, what }: { kind: MyLoadFailure; courseSlug?: string | null; onRetry: () => void; what: "list" | "course" }) {
  const retry = (
    <Button variant="secondary" onClick={onRetry}>
      Thử lại
    </Button>
  );
  switch (kind) {
    case "session":
      return (
        <Alert tone="warning" title="Phiên đăng nhập đã kết thúc">
          Hãy đăng nhập lại để xem khóa học của bạn.
        </Alert>
      );
    case "not_owned":
      return (
        <Alert
          tone="warning"
          title="Bạn chưa sở hữu khóa học này"
          action={<ButtonLink href={courseSlug ? routes.course(courseSlug) : routes.catalog} variant="secondary">{courseSlug ? "Tới trang khóa học" : "Xem danh sách khóa học"}</ButtonLink>}
        >
          Khóa học chưa được kích hoạt cho tài khoản của bạn, đang chờ duyệt hoặc quyền truy cập đã bị thu hồi.
        </Alert>
      );
    case "not_found":
      return (
        <Alert tone="danger" title="Không tìm thấy khóa học" action={<ButtonLink href={routes.myCourses} variant="secondary">Khóa học của tôi</ButtonLink>}>
          Khóa học này không còn tồn tại.
        </Alert>
      );
    case "throttled":
      return (
        <Alert tone="warning" title="Bạn thao tác hơi nhanh" action={retry}>
          Đợi một chút rồi thử lại.
        </Alert>
      );
    default:
      return (
        <Alert tone="danger" title={what === "list" ? "Không tải được danh sách khóa học của bạn" : "Không tải được tiến độ khóa học"} action={retry}>
          Kiểm tra kết nối mạng rồi thử lại.
        </Alert>
      );
  }
}
