import Link from "next/link";
import { EmptyState } from "@vitaminvui/ui";

/** Trang 403 chuẩn (design US-016 §3): vai trò không có quyền với màn này. Quyền thật do API kiểm. */
export function ForbiddenView() {
  return (
    <div className="mx-auto max-w-xl py-12" data-testid="forbidden-view">
      <EmptyState title="Bạn không có quyền truy cập trang này." description="Nếu bạn cho rằng đây là nhầm lẫn, vui lòng liên hệ Admin." />
      <p className="mt-4 text-center">
        <Link href="/quan-tri" className="inline-flex min-h-11 items-center font-medium text-indigo-700 hover:underline">
          Về trang tổng quan
        </Link>
      </p>
    </div>
  );
}
