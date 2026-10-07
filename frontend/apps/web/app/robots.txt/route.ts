import { absoluteUrl } from "@/lib/catalog/seo";

/** ISR 1 giờ (tasks.md FW2). Route handler không đi qua proxy.ts (matcher loại trừ robots.txt). */
export const revalidate = 3600;

export function GET() {
  const body = [
    "User-agent: *",
    "Allow: /",
    "Disallow: /dang-nhap",
    "Disallow: /dang-ky",
    "Disallow: /xac-thuc-otp",
    "Disallow: /hoc/",
    "Disallow: /gio-hang",
    "Disallow: /checkout",
    "",
    `Sitemap: ${absoluteUrl("/sitemap.xml")}`,
    "",
  ].join("\n");
  return new Response(body, { headers: { "Content-Type": "text/plain; charset=utf-8" } });
}
