import { describe, expect, it, vi } from "vitest";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_SITE_URL: "http://api.localhost:3000" } }));

import { courseEntry, renderSitemap, staticEntries } from "./sitemap";

describe("sitemap", () => {
  it("có trang chủ, /khoa-hoc và lớp 6–12", () => {
    const locs = staticEntries().map((e) => e.loc);
    expect(locs).toHaveLength(2 + 7);
    expect(locs.some((l) => l.endsWith("/lop-6"))).toBe(true);
    expect(locs.some((l) => l.endsWith("/lop-12"))).toBe(true);
  });

  it("render XML, escape ký tự đặc biệt, lastmod ISO", () => {
    const xml = renderSitemap([{ loc: "http://x/a?b=1&c=2" }, courseEntry("toan-9", "2026-10-01T03:00:00+07:00")]);
    expect(xml).toContain("<loc>http://x/a?b=1&amp;c=2</loc>");
    expect(xml).toContain("<lastmod>2026-09-30T20:00:00.000Z</lastmod>");
    expect(xml.startsWith('<?xml version="1.0"')).toBe(true);
  });

  it("published_at null/hỏng -> không có lastmod", () => {
    expect(courseEntry("a", null).lastmod).toBeUndefined();
    expect(courseEntry("a", "không phải ngày").lastmod).toBeUndefined();
  });
});
