import { ApiError } from "@vitaminvui/api-client";
import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_API_URL: "http://api.test" } }));
vi.mock("@/lib/api", () => ({ authFetch: vi.fn() }));
const clearCsrfToken = vi.fn();
vi.mock("@vitaminvui/api-client", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@vitaminvui/api-client")>()),
  getCsrfToken: vi.fn(async () => "csrf-1"),
  getDeviceId: () => "dev-1",
  clearCsrfToken: (...a: unknown[]) => clearCsrfToken(...a),
}));

import { downloadDataExport } from "./api";

const fetchMock = vi.fn();
beforeEach(() => {
  fetchMock.mockReset();
  clearCsrfToken.mockReset();
  vi.stubGlobal("fetch", fetchMock);
});

const fileRes = (disposition: string | null) =>
  new Response('{"format_version":1}', { status: 200, headers: disposition ? { "Content-Disposition": disposition } : {} });
const jsonRes = (status: number, body: unknown, headers: Record<string, string> = {}) =>
  new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json", ...headers } });

describe("downloadDataExport", () => {
  it("gửi X-CSRF-TOKEN + X-Device-Id + credentials include, tên file từ Content-Disposition", async () => {
    fetchMock.mockResolvedValueOnce(fileRes('attachment; filename="vitaminvui-du-lieu-ca-nhan-20261008.json"'));
    const { blob, filename } = await downloadDataExport("matkhau-123");
    expect(filename).toBe("vitaminvui-du-lieu-ca-nhan-20261008.json");
    expect(await blob.text()).toContain("format_version");
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("http://api.test/api/v1/me/data-export");
    expect(init.method).toBe("POST");
    expect(init.credentials).toBe("include");
    const h = init.headers as Record<string, string>;
    expect(h["X-CSRF-TOKEN"]).toBe("csrf-1");
    expect(h["X-Device-Id"]).toBe("dev-1");
    expect(JSON.parse(init.body as string)).toEqual({ current_password: "matkhau-123" });
  });

  it("tên file không hợp lệ -> tên mặc định theo ngày", async () => {
    fetchMock.mockResolvedValueOnce(fileRes('attachment; filename="../../evil.exe"'));
    const { filename } = await downloadDataExport("x");
    expect(filename).toMatch(/^vitaminvui-du-lieu-ca-nhan-\d{8}\.json$/);
  });

  it("419 thử lại đúng một lần (xoá cache CSRF) rồi thành công", async () => {
    fetchMock.mockResolvedValueOnce(jsonRes(419, { message: "CSRF" })).mockResolvedValueOnce(fileRes(null));
    await downloadDataExport("x");
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(clearCsrfToken).toHaveBeenCalledTimes(1);
  });

  it("419 liên tiếp: không lặp vô hạn, ném ApiError", async () => {
    fetchMock.mockResolvedValue(jsonRes(419, { message: "CSRF" }));
    await expect(downloadDataExport("x")).rejects.toBeInstanceOf(ApiError);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it("429 DATA_EXPORT_LIMIT -> ApiError mang errors.resets_at và Retry-After", async () => {
    fetchMock.mockResolvedValueOnce(
      jsonRes(429, { message: "m", code: "DATA_EXPORT_LIMIT", errors: { limit: 2, resets_at: "2026-10-09T00:00:00+07:00" } }, { "Retry-After": "3600" }),
    );
    const err = (await downloadDataExport("x").catch((e: unknown) => e)) as ApiError;
    expect(err).toBeInstanceOf(ApiError);
    expect(err.code).toBe("DATA_EXPORT_LIMIT");
    expect(err.retryAfterSeconds).toBe(3600);
  });
});
