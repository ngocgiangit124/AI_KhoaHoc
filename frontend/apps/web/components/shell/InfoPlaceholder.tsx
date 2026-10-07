import type { ReactNode } from "react";
import { ButtonLink, EmptyState, Sheet } from "@vitaminvui/ui/v2";
import { routes } from "@/lib/routes";

/**
 * Trang giữ chỗ cho nội dung pháp lý chưa có (V2/pháp chế soạn): nói rõ vì sao chưa có và đường đi tiếp, thay cho 404, để ô đồng ý
 * ở form đăng ký có đích thật (không liên kết chết). Dựng từ `InfoPage` của bản xem trước, bỏ khung xem trước.
 */
export function InfoPlaceholder({ title, description, icon }: { title: string; description: ReactNode; icon: ReactNode }) {
  return (
    <div className="mx-auto w-full max-w-2xl px-4 py-10 sm:px-6">
      <Sheet padding="none">
        <EmptyState
          headingLevel="h1"
          icon={icon}
          title={title}
          description={description}
          action={<ButtonLink href={routes.catalog}>Xem khóa học</ButtonLink>}
          secondaryAction={<ButtonLink href={routes.home} variant="ghost">Về trang chủ</ButtonLink>}
        />
      </Sheet>
    </div>
  );
}
