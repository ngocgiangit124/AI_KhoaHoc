import { absoluteUrl } from "./seo";
import { GRADES } from "./query";

export interface SitemapEntry {
  loc: string;
  lastmod?: string;
}

function escapeXml(value: string): string {
  return value.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&apos;");
}

/** Trang tĩnh của danh mục: trang chủ, /khoa-hoc, /lop-6…12. */
export function staticEntries(): SitemapEntry[] {
  return [
    { loc: absoluteUrl("/") },
    { loc: absoluteUrl("/khoa-hoc") },
    ...GRADES.map((g) => ({ loc: absoluteUrl(`/lop-${g}`) })),
  ];
}

export function courseEntry(slug: string, publishedAt: string | null): SitemapEntry {
  const date = publishedAt ? new Date(publishedAt) : null;
  return {
    loc: absoluteUrl(`/khoa-hoc/${slug}`),
    lastmod: date && !Number.isNaN(date.getTime()) ? date.toISOString() : undefined,
  };
}

export function renderSitemap(entries: SitemapEntry[]): string {
  const urls = entries
    .map((e) => `  <url><loc>${escapeXml(e.loc)}</loc>${e.lastmod ? `<lastmod>${e.lastmod}</lastmod>` : ""}</url>`)
    .join("\n");
  return `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls}\n</urlset>\n`;
}
