import { ButtonLink, EmptyState, IconSearch } from "@vitaminvui/ui/v2";
import { routes } from "@/lib/routes";

/**
 * Đặt ở segment `khoa-hoc` (không phải `[slug]`) vì `notFound()` được gọi từ `[slug]/layout.tsx`
 * và boundary của segment nằm DƯỚI layout của chính nó. Khung trang (header/footer) do `(site)/layout.tsx`.
 */
export default function CourseNotFound() {
  return (
    <div className="mx-auto w-full max-w-xl px-4 py-8">
      <EmptyState
        headingLevel="h1"
        icon={<IconSearch size={32} />}
        title="Không tìm thấy khóa học"
        description="Khóa học bạn tìm không tồn tại hoặc đã ngừng hiển thị."
        action={<ButtonLink href={routes.catalog}>Về trang danh mục</ButtonLink>}
        secondaryAction={
          <ButtonLink href={routes.home} variant="ghost">
            Về trang chủ
          </ButtonLink>
        }
      />
    </div>
  );
}
