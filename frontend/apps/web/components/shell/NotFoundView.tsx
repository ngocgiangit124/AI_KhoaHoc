import { ButtonLink, EmptyState, IconSearch } from "@vitaminvui/ui/v2";
import { routes } from "@/lib/routes";

/** Nội dung 404 chung: tiêu đề h1 + đường về (design-system-v2 §11.2 "Không tìm thấy"). */
export function NotFoundView() {
  return (
    <div className="mx-auto w-full max-w-xl px-4 py-8">
      <EmptyState
        headingLevel="h1"
        icon={<IconSearch size={32} />}
        title="Không tìm thấy trang"
        description="Trang bạn tìm không tồn tại hoặc đã bị xoá."
        action={<ButtonLink href={routes.catalog}>Xem danh mục khóa học</ButtonLink>}
        secondaryAction={
          <ButtonLink href={routes.home} variant="ghost">
            Về trang chủ
          </ButtonLink>
        }
      />
    </div>
  );
}
