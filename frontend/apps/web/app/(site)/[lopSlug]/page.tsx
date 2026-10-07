import type { Metadata } from "next";
import { notFound, redirect } from "next/navigation";
import { CatalogBusy } from "@/components/catalog/CatalogBusy";
import { CatalogView } from "@/components/catalog/CatalogView";
import { fetchCourses, fetchPublicConfig, fetchSubjects } from "@/lib/catalog/api";
import { isUpstreamBusy } from "@/lib/catalog/busy";
import { hasActiveFilters, parseCatalogQuery, parseGradeSegment, toPageHref } from "@/lib/catalog/query";
import { gradeIntro } from "@/lib/catalog/seo";

/**
 * `/lop-{grade}` — Next.js App Router không hỗ trợ segment có tiền tố (`lop-[grade]`), nên dùng
 * 1 segment động ở gốc và tự parse `lop-6`…`lop-12`; mọi giá trị khác -> `notFound()` (API trả
 * 422 cho grade sai, FE chặn trước và hiện 404 — US-002 §3). Route tĩnh (khoa-hoc, dang-nhap…)
 * luôn được ưu tiên hơn segment động nên không bị che.
 *
 * ADR-004 §2.7: render động + nonce; dữ liệu cache ở Data Cache (revalidate 60, tag `catalog`).
 */
export const dynamic = "force-dynamic";

export async function generateMetadata({ params, searchParams }: PageProps<"/[lopSlug]">): Promise<Metadata> {
  const grade = parseGradeSegment((await params).lopSlug);
  if (grade === null) return {};
  const query = parseCatalogQuery(await searchParams, grade);
  const title = `Khóa học Toán lớp ${grade}`;
  const description = gradeIntro(grade);
  return {
    title,
    description,
    alternates: { canonical: `/lop-${grade}` },
    robots: hasActiveFilters(query, true) || query.sort !== "newest" || query.page > 1 ? { index: false, follow: true } : undefined,
    openGraph: { title, description, type: "website", locale: "vi_VN" },
  };
}

export default async function GradeCatalogPage({ params, searchParams }: PageProps<"/[lopSlug]">) {
  const grade = parseGradeSegment((await params).lopSlug);
  if (grade === null) notFound();

  const basePath = `/lop-${grade}`;
  const query = parseCatalogQuery(await searchParams, grade);
  let data;
  try {
    data = await Promise.all([fetchSubjects(), fetchCourses(query), fetchPublicConfig()]);
  } catch (err) {
    if (isUpstreamBusy(err)) return <CatalogBusy href={basePath} />;
    throw err;
  }
  const [subjects, courses, config] = data;

  // Trang vượt quá trang cuối (link cũ, dữ liệu giảm) -> về trang cuối thay vì hiện "rỗng".
  if (courses.data.length === 0 && query.page > Math.max(1, courses.meta.last_page)) {
    redirect(toPageHref(basePath, { ...query, page: Math.max(1, courses.meta.last_page) }, true));
  }

  return (
    <CatalogView
      basePath={basePath}
      query={query}
      fixedGrade={grade}
      subjects={subjects}
      courses={courses}
      paidCheckoutEnabled={config.paid_checkout_enabled}
      title={`Khóa học Toán lớp ${grade}`}
      intro={gradeIntro(grade)}
      breadcrumb={[{ label: "Trang chủ", href: "/" }, { label: `Lớp ${grade}` }]}
    />
  );
}
