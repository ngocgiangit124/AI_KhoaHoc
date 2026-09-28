import { describe, expect, it } from "vitest";
import { maskEmail, maskPhone } from "./maskContact";

describe("maskEmail", () => {
  it("che phần local sau 3 ký tự đầu, giữ nguyên domain (mockup US-001)", () => {
    expect(maskEmail("minhan2010@gmail.com")).toBe("min***@gmail.com");
  });

  it("phần local ngắn hơn 3 ký tự vẫn hiện hết phần đó rồi che", () => {
    expect(maskEmail("ab@x.com")).toBe("ab***@x.com");
  });

  it("chuỗi không có @ thì trả nguyên văn (không phải email hợp lệ, không cố che)", () => {
    expect(maskEmail("khong-phai-email")).toBe("khong-phai-email");
  });
});

describe("maskPhone", () => {
  it("che 4 số giữa, giữ 3 số đầu + 3 số cuối", () => {
    expect(maskPhone("0987654321")).toBe("098****321");
  });

  it("số quá ngắn thì trả nguyên văn", () => {
    expect(maskPhone("0987")).toBe("0987");
  });
});
