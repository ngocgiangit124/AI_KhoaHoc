import { ButtonLink, EmptyState, IconLayers } from "@vitaminvui/ui/v2";

export default function V2AdminNotFound() {
  return (
    <main className="mx-auto flex w-full max-w-xl flex-1 items-center px-4 py-16">
      <EmptyState
        headingLevel="h1"
        icon={<IconLayers size={32} />}
        title="Màn này chưa có trong bản xem trước"
        description="Bản xem trước quản trị gồm danh sách khóa học và màn sửa khóa học. Các màn khác dùng cùng khung và thành phần."
        action={<ButtonLink href="/v2/quan-tri/khoa-hoc">Về danh sách khóa học</ButtonLink>}
      />
    </main>
  );
}
