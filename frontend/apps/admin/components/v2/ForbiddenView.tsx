import { ButtonLink, EmptyState, IconLock } from "@vitaminvui/ui/v2";

/** Trang 403 trong khung quản trị (vai trò không có quyền vào màn này). */
export function ForbiddenView({ homeHref = "/v2/quan-tri/khoa-hoc" }: { homeHref?: string }) {
  return (
    <EmptyState
      headingLevel="h1"
      icon={<IconLock size={32} />}
      title="Bạn không có quyền truy cập trang này"
      description="Nếu cần quyền, hãy liên hệ Admin."
      action={<ButtonLink href={homeHref}>Về danh sách khóa học</ButtonLink>}
    />
  );
}
