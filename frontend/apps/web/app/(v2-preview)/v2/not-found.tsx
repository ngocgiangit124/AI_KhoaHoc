import { ButtonLink, EmptyState, IconSearch } from "@vitaminvui/ui/v2";
import { routes } from "@/lib/v2/routes";

/** 404 trong bản xem trước (khóa học không tồn tại/đã gỡ: US-003 §4). */
export default function V2NotFound() {
  return (
    <main className="mx-auto flex w-full max-w-xl flex-1 items-center px-4 py-16">
      <EmptyState
        headingLevel="h1"
        icon={<IconSearch size={32} />}
        title="Không tìm thấy khóa học"
        description="Khóa học bạn tìm không tồn tại hoặc đã ngừng hiển thị."
        action={<ButtonLink href={routes.catalog}>Về trang danh mục</ButtonLink>}
        secondaryAction={<ButtonLink href={routes.home} variant="ghost">Về trang chủ</ButtonLink>}
      />
    </main>
  );
}
