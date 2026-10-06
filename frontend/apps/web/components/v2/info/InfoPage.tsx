import type { ReactNode } from "react";
import { ButtonLink, EmptyState } from "@vitaminvui/ui/v2";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { StudentShell } from "@/components/v2/StudentShell";
import { routes } from "@/lib/v2/routes";

/**
 * Trang giữ chỗ trong hệ thiết kế cho phần chưa mở (V2/pháp chế): nói rõ vì sao chưa có và đường đi tiếp,
 * thay cho 404. Không dùng cho tính năng đã có.
 */
export function InfoPage({ title, description, icon, loggedIn = false }: { title: string; description: ReactNode; icon: ReactNode; loggedIn?: boolean }) {
  return (
    <StudentShell current="catalog" loggedIn={loggedIn} preview={<PreviewBar note="Trang giữ chỗ — nội dung sẽ có ở đợt sau" />}>
      <div className="mx-auto max-w-2xl px-4 py-10 sm:px-6">
        <EmptyState
          headingLevel="h1"
          icon={icon}
          title={title}
          description={description}
          action={<ButtonLink href={routes.catalog}>Xem khóa học</ButtonLink>}
          secondaryAction={<ButtonLink href={routes.home} variant="ghost">Về trang chủ</ButtonLink>}
        />
      </div>
    </StudentShell>
  );
}
