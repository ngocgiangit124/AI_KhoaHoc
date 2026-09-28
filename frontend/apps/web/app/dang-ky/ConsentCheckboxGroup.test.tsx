import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { ConsentCheckboxGroup } from "./ConsentCheckboxGroup";

describe("ConsentCheckboxGroup", () => {
  it("KHÔNG tick sẵn 2 checkbox (S7)", () => {
    render(
      <ConsentCheckboxGroup
        termsChecked={false}
        privacyChecked={false}
        onTermsChange={vi.fn()}
        onPrivacyChange={vi.fn()}
        policyVersion="2026-09"
      />,
    );

    const checkboxes = screen.getAllByRole("checkbox");
    expect(checkboxes).toHaveLength(2);
    for (const checkbox of checkboxes) {
      expect(checkbox).not.toBeChecked();
    }
  });

  it("gọi onTermsChange/onPrivacyChange khi bấm từng checkbox", async () => {
    const user = userEvent.setup();
    const onTermsChange = vi.fn();
    const onPrivacyChange = vi.fn();
    render(
      <ConsentCheckboxGroup
        termsChecked={false}
        privacyChecked={false}
        onTermsChange={onTermsChange}
        onPrivacyChange={onPrivacyChange}
        policyVersion="2026-09"
      />,
    );

    const [terms, privacy] = screen.getAllByRole("checkbox");
    await user.click(terms!);
    await user.click(privacy!);

    expect(onTermsChange).toHaveBeenCalledWith(true);
    expect(onPrivacyChange).toHaveBeenCalledWith(true);
  });

  it("hiển thị đúng 1 dòng lỗi gộp khi thiếu đồng ý, và hiển thị policy_version", () => {
    render(
      <ConsentCheckboxGroup
        termsChecked={true}
        privacyChecked={false}
        onTermsChange={vi.fn()}
        onPrivacyChange={vi.fn()}
        error="Vui lòng đồng ý với cả điều khoản sử dụng và chính sách xử lý dữ liệu cá nhân"
        policyVersion="2026-09"
      />,
    );

    expect(screen.getAllByRole("alert")).toHaveLength(1);
    expect(screen.getByText(/Phiên bản chính sách: 2026-09/)).toBeInTheDocument();
  });
});
