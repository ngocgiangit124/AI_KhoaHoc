import { ButtonLink } from "@vitaminvui/ui/v2";
import { CatalogBusy } from "@/components/catalog/CatalogBusy";
import { fetchPublicConfig } from "@/lib/catalog/api";
import { isUpstreamBusy } from "@/lib/catalog/busy";
import { routes } from "@/lib/routes";

/**
 * Trang chủ tạm (FE0 + khung v2): chọn lớp 6–12 từ `/config/public`. Trang chủ đầy đủ theo US-019 là FW8
 * (bản xem trước ở `/v2`).
 *
 * `dynamic = 'force-dynamic'`: CSP có nonce (proxy.ts) buộc mọi trang render động — quyết
 * định kiến trúc đã chốt ở ADR-004 §2.7 (phương án (a)): render động + Next Data Cache
 * (`next.revalidate`) ở tầng fetch, KHÔNG có Full Route Cache. `publicFetchServer` dưới
 * đây dùng `revalidate: 60` đúng theo §2.7 (khớp `Cache-Control: public, max-age=60` phía
 * Laravel — ADR-004 §2.5).
 */
export const dynamic = "force-dynamic";

export default async function HomePage() {
  let config;
  try {
    config = await fetchPublicConfig();
  } catch (err) {
    if (isUpstreamBusy(err)) return <CatalogBusy href="/" />;
    throw err;
  }

  return (
    <div className="mx-auto w-full max-w-6xl px-4 pb-14 pt-8 sm:px-6">
      <h1 className="text-display font-extrabold tracking-heading text-ink">VitaminVui — Học Toán 6–12</h1>
      <p className="mt-2 max-w-2xl text-base text-ink-soft">Chọn lớp học để xem danh mục khóa học phù hợp.</p>

      <div className="mt-4">
        <ButtonLink href={routes.catalog} variant="secondary">
          Xem tất cả khóa học
        </ButtonLink>
      </div>

      <ul className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
        {config.grades.map((grade) => (
          <li key={grade}>
            <a
              href={routes.grade(grade)}
              className="focus-ring flex h-16 items-center justify-center rounded-card border border-line bg-surface text-heading font-extrabold text-primary transition-colors hover:border-primary hover:bg-primary-soft"
            >
              Lớp {grade}
            </a>
          </li>
        ))}
      </ul>
    </div>
  );
}
