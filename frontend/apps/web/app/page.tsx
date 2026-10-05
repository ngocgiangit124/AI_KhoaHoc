import { AuthNav } from "@/components/auth/AuthNav";
import { RegisterSuccessBanner } from "@/components/auth/RegisterSuccessBanner";
import { publicFetchServer } from "@/lib/api.server";
import { parsePublicConfig } from "@/lib/types/config";

/**
 * Trang mẫu FE0 (tasks.md): gọi `publicFetch('/api/v1/config/public')` và hiển thị lớp
 * 6–12.
 *
 * `dynamic = 'force-dynamic'`: CSP có nonce (proxy.ts) buộc mọi trang render động — quyết
 * định kiến trúc đã chốt ở ADR-004 §2.7 (phương án (a)): render động + Next Data Cache
 * (`next.revalidate`) ở tầng fetch, KHÔNG có Full Route Cache. `publicFetchServer` dưới
 * đây dùng `revalidate: 60` đúng theo §2.7 (khớp `Cache-Control: public, max-age=60` phía
 * Laravel — ADR-004 §2.5).
 */
export const dynamic = "force-dynamic";

export default async function HomePage() {
  const raw = await publicFetchServer<unknown>("/api/v1/config/public", { revalidate: 60 });
  const config = parsePublicConfig(raw);

  return (
    <main className="mx-auto max-w-5xl px-4 py-10">
      <div className="mb-6 flex justify-end">
        <AuthNav />
      </div>
      <RegisterSuccessBanner />
      <h1 className="text-2xl font-bold text-gray-900 md:text-3xl">VitaminVui — Học Toán 6–12</h1>
      <p className="mt-2 text-sm text-gray-700 md:text-base">
        Chọn lớp học để xem danh mục khóa học phù hợp.
      </p>

      <ul className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
        {config.grades.map((grade) => (
          <li key={grade}>
            <a
              href={`/lop-${grade}`}
              className="flex h-16 items-center justify-center rounded-lg border border-gray-200 text-lg font-semibold text-indigo-600 transition-colors hover:bg-indigo-50"
            >
              Lớp {grade}
            </a>
          </li>
        ))}
      </ul>
    </main>
  );
}
