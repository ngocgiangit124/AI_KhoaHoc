"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { Button, ButtonLink, EmptyState, IconAlertTriangle, IconListChecks, IconLock } from "@vitaminvui/ui/v2";
import type { QuizLoadFailure } from "@/lib/quiz/errors";
import { routes } from "@/lib/routes";

/** Màn thông báo toàn trang cho các lỗi tải quiz (không có nội dung để hiện). */
export function QuizNotice({ kind, courseSlug, backHref, onRetry }: { kind: QuizLoadFailure; courseSlug: string | null; backHref: string; onRetry?: () => void }) {
  const router = useRouter();
  const redirect = kind === "not_owned" && courseSlug ? routes.course(courseSlug) : null;
  useEffect(() => {
    if (redirect) router.replace(redirect);
  }, [redirect, router]);

  let view: { icon: React.ReactNode; title: string; description: string; action: React.ReactNode };
  switch (kind) {
    case "not_owned":
      view = {
        icon: <IconLock size={40} />,
        title: "Bạn chưa sở hữu khóa học này",
        description: courseSlug ? "Đang chuyển tới trang khóa học để bạn đăng ký hoặc mua." : "Bài kiểm tra này chỉ dành cho học sinh đã đăng ký hoặc mua khóa học.",
        action: <ButtonLink href={courseSlug ? routes.course(courseSlug) : routes.catalog}>{courseSlug ? "Tới trang khóa học" : "Xem danh sách khóa học"}</ButtonLink>,
      };
      break;
    case "not_found":
      view = {
        icon: <IconAlertTriangle size={40} />,
        title: "Không tìm thấy bài kiểm tra",
        description: "Bài kiểm tra hoặc khóa học này không còn tồn tại.",
        action: <ButtonLink href={routes.catalog}>Về danh mục khóa học</ButtonLink>,
      };
      break;
    case "not_ready":
      view = {
        icon: <IconListChecks size={40} />,
        title: "Bài kiểm tra chưa sẵn sàng",
        description: "Giáo viên đang chuẩn bị câu hỏi, bạn quay lại sau nhé.",
        action: <ButtonLink href={backHref}>Quay lại bài học</ButtonLink>,
      };
      break;
    case "throttled":
      view = {
        icon: <IconAlertTriangle size={40} />,
        title: "Bạn thao tác hơi nhanh",
        description: "Đợi một chút rồi thử lại.",
        action: onRetry ? <Button onClick={onRetry}>Thử lại</Button> : <ButtonLink href={backHref}>Quay lại</ButtonLink>,
      };
      break;
    default:
      view = {
        icon: <IconAlertTriangle size={40} />,
        title: "Không tải được bài kiểm tra",
        description: "Có thể do kết nối mạng chập chờn. Hãy thử lại.",
        action: onRetry ? <Button onClick={onRetry}>Thử lại</Button> : <ButtonLink href={backHref}>Quay lại</ButtonLink>,
      };
  }
  return (
    <main id="noi-dung" className="flex flex-1 items-center justify-center px-4">
      <EmptyState headingLevel="h1" icon={view.icon} title={view.title} description={view.description} action={view.action} />
    </main>
  );
}
