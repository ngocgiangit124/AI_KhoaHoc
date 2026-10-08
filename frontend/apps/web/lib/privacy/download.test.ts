import { describe, expect, it } from "vitest";
import { defaultExportFilename, filenameFromDisposition } from "./download";

const NOW = new Date("2026-10-08T18:30:00Z"); // 01:30 ngày 09/10 giờ VN

describe("tên file xuất dữ liệu", () => {
  it("mặc định theo ngày giờ VN", () => {
    expect(defaultExportFilename(NOW)).toBe("vitaminvui-du-lieu-ca-nhan-20261009.json");
  });
  it("đọc filename từ Content-Disposition", () => {
    expect(filenameFromDisposition('attachment; filename="vitaminvui-du-lieu-ca-nhan-20261008.json"', NOW)).toBe("vitaminvui-du-lieu-ca-nhan-20261008.json");
    expect(filenameFromDisposition("attachment; filename=a_b-1.json", NOW)).toBe("a_b-1.json");
  });
  it("thiếu header, tên lạ hoặc có đường dẫn -> tên mặc định", () => {
    const def = defaultExportFilename(NOW);
    expect(filenameFromDisposition(null, NOW)).toBe(def);
    expect(filenameFromDisposition('attachment; filename="../../x.json"', NOW)).toBe(def);
    expect(filenameFromDisposition('attachment; filename="evil.exe"', NOW)).toBe(def);
    expect(filenameFromDisposition("attachment", NOW)).toBe(def);
  });
});
