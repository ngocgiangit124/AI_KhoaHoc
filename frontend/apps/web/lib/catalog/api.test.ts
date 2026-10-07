import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("server-only", () => ({}));
const fetchMock = vi.hoisted(() => vi.fn());
vi.mock("@/lib/api.server", () => ({ publicFetchServer: fetchMock }));
vi.mock("next/headers", () => ({ headers: async () => new Headers({ "x-forwarded-for": "203.0.113.9" }) }));
vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_SITE_URL: "http://site.test" } }));

import { ApiError } from "@vitaminvui/api-client";
import { fetchCourse, fetchCourses } from "./api";
import type { CatalogQuery } from "./query";

const base: CatalogQuery = { grade: null, subjectIds: [], teacherId: null, q: "", sort: "newest", page: 1 };
const empty = { data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } };

beforeEach(() => {
  fetchMock.mockReset();
  fetchMock.mockResolvedValue(empty);
});

describe("fetchCourses: X-Client-IP (ADR-004 §2.8)", () => {
  it("không q, không teacher_id -> không gắn IP (dùng chung Data Cache)", async () => {
    await fetchCourses(base);
    expect(fetchMock.mock.calls[0]?.[1].clientIp).toBeNull();
  });
  it("có q -> gắn IP khách", async () => {
    await fetchCourses({ ...base, q: "hinh" });
    expect(fetchMock.mock.calls[0]?.[1].clientIp).toBe("203.0.113.9");
  });
  it("có teacher_id -> gắn IP khách, URL mang teacher_id", async () => {
    await fetchCourses({ ...base, teacherId: 7 });
    expect(fetchMock.mock.calls[0]?.[0]).toContain("teacher_id=7");
    expect(fetchMock.mock.calls[0]?.[1].clientIp).toBe("203.0.113.9");
  });
});

describe("fetchCourse: 404 của backend phải có hiệu lực (BUG-2)", () => {
  it("không dùng Data Cache (revalidate: false) để bản cũ không sống mãi khi lần làm mới trả 404", async () => {
    fetchMock.mockRejectedValue(new ApiError(404, { message: "x" }));
    expect(await fetchCourse("khoa-da-ngung-ban")).toBeNull();
    expect(fetchMock.mock.calls[0]?.[1]).toEqual({ revalidate: false });
  });
  it("slug sai định dạng -> null, không gọi API", async () => {
    expect(await fetchCourse("A/b")).toBeNull();
    expect(fetchMock).not.toHaveBeenCalled();
  });
  it("lỗi khác 404 vẫn ném lên", async () => {
    fetchMock.mockRejectedValue(new ApiError(500, { message: "x" }));
    await expect(fetchCourse("khoa-khac")).rejects.toBeInstanceOf(ApiError);
  });
});
