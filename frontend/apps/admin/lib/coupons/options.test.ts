import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import * as courses from "@/lib/courses/api";
import { cheapestPublishedPrice, resetCheapestCache } from "./options";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
vi.mock("@/lib/courses/api");
vi.mock("@/lib/subjects/api");

const page = (prices: number[]) => ({ data: prices.map((price, i) => ({ id: i, price })), meta: { current_page: 1, per_page: 50, total: prices.length, last_page: 1 }, links: { next: null, prev: null } }) as never;

beforeEach(() => {
  vi.resetAllMocks();
  resetCheapestCache();
});

describe("cheapestPublishedPrice", () => {
  it("lấy giá nhỏ nhất > 0 và cache 60 giây (kể cả null)", async () => {
    vi.mocked(courses.listCourses).mockResolvedValue(page([0, 500000, 300000]));
    expect(await cheapestPublishedPrice()).toBe(300000);
    expect(await cheapestPublishedPrice()).toBe(300000);
    expect(courses.listCourses).toHaveBeenCalledTimes(1);
    resetCheapestCache();
    vi.mocked(courses.listCourses).mockResolvedValue(page([]));
    expect(await cheapestPublishedPrice()).toBeNull();
    expect(await cheapestPublishedPrice()).toBeNull();
    expect(courses.listCourses).toHaveBeenCalledTimes(2);
  });
  it("lỗi 429 không quét lại liên tục trong TTL", async () => {
    vi.mocked(courses.listCourses).mockRejectedValue(new ApiError(429, { message: "x" }));
    await expect(cheapestPublishedPrice()).rejects.toBeInstanceOf(ApiError);
    expect(await cheapestPublishedPrice()).toBeNull();
    expect(courses.listCourses).toHaveBeenCalledTimes(1);
  });
});
