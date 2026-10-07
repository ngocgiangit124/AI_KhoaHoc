import { ButtonLink, EmptyState, IconLayers } from "@vitaminvui/ui/v2";

export default function NotFound() {
  return (
    <main className="mx-auto flex w-full max-w-xl flex-1 items-center px-4 py-16">
      <EmptyState
        headingLevel="h1"
        icon={<IconLayers size={32} />}
        title="Không tìm thấy trang"
        description="Trang bạn tìm không tồn tại hoặc đã bị xoá."
        action={<ButtonLink href="/quan-tri">Về trang tổng quan</ButtonLink>}
      />
    </main>
  );
}
