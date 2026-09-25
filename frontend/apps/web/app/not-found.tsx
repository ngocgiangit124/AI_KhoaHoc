import { EmptyState } from "@vitaminvui/ui";

export default function NotFound() {
  return (
    <main className="mx-auto max-w-xl px-4 py-16">
      <EmptyState title="Không tìm thấy trang" description="Trang bạn tìm không tồn tại hoặc đã bị xoá." />
    </main>
  );
}
