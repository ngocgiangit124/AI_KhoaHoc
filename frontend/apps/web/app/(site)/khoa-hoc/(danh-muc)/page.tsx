import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { CatalogBusy } from "@/components/catalog/CatalogBusy";
import { CatalogView } from "@/components/catalog/CatalogView";
import { fetchCourses, fetchPublicConfig, fetchSubjects } from "@/lib/catalog/api";
import { isUpstreamBusy } from "@/lib/catalog/busy";
import { hasActiveFilters, parseCatalogQuery, toPageHref } from "@/lib/catalog/query";

/**
 * ADR-004 §2.7: CSP nonce -> render động (không ISR/PPR); cache ở Data Cache của
 * `publicFetchServer` (revalidate 60, tag `catalog`) — xem `lib/catalog/api.ts`.
 */
export const dynamic = "force-dynamic";

export async function generateMetadata({ searchParams }: PageProps<"/khoa-hoc">): Promise<Metadata> {
  const query = parseCatalogQuery(await searchParams);
  const title = "Khóa học Toán lớp 6–12";
  const description = "Danh mục khóa học Toán trực tuyến VitaminVui cho học sinh lớp 6 đến lớp 12: lọc theo lớp, chuyên đề, tìm kiếm và sắp xếp.";
  return {
    title,
    description,
    alternates: { canonical: "/khoa-hoc" },
    // Trang đã lọc/phân trang trùng nội dung với trang gốc: không đưa vào chỉ mục.
    robots: hasActiveFilters(query, false) || query.sort !== "newest" || query.page > 1 ? { index: false, follow: true } : undefined,
    openGraph: { title, description, type: "website", locale: "vi_VN" },
  };
}

export default async function CatalogPage({ searchParams }: PageProps<"/khoa-hoc">) {
  const query = parseCatalogQuery(await searchParams);
  let data;
  try {
    data = await Promise.all([fetchSubjects(), fetchCourses(query), fetchPublicConfig()]);
  } catch (err) {
    if (isUpstreamBusy(err)) return <CatalogBusy href="/khoa-hoc" />;
    throw err;
  }
  const [subjects, courses, config] = data;

  // Trang vượt quá trang cuối (link cũ, dữ liệu giảm) -> về trang cuối thay vì hiện "rỗng".
  if (courses.data.length === 0 && query.page > Math.max(1, courses.meta.last_page)) {
    redirect(toPageHref("/khoa-hoc", { ...query, page: Math.max(1, courses.meta.last_page) }, false));
  }

  return (
    <CatalogView
      basePath="/khoa-hoc"
      query={query}
      fixedGrade={null}
      subjects={subjects}
      courses={courses}
      paidCheckoutEnabled={config.paid_checkout_enabled}
      title="Khóa học Toán"
      intro="Chọn lớp, chuyên đề hoặc tìm theo từ khóa để tìm khóa học phù hợp."
      breadcrumb={[{ label: "Trang chủ", href: "/" }, { label: "Khóa học" }]}
    />
  );
}
