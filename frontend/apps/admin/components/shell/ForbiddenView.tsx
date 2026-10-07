import { ButtonLink, EmptyState, IconLock } from "@vitaminvui/ui/v2";

/** Trang 403 chuẩn (design US-016 §3): vai trò không có quyền với màn này. Quyền thật do API kiểm. */
export function ForbiddenView() {
  return (
    <div className="mx-auto max-w-xl py-8" data-testid="forbidden-view">
      <EmptyState
        headingLevel="h1"
        icon={<IconLock size={32} />}
        title="Bạn không có quyền truy cập trang này."
        description="Nếu bạn cho rằng đây là nhầm lẫn, vui lòng liên hệ Admin."
        action={<ButtonLink href="/quan-tri">Về trang tổng quan</ButtonLink>}
      />
    </div>
  );
}
