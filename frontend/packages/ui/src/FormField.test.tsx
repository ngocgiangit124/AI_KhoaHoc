import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { FormField } from "./FormField";
import { TextInput } from "./TextInput";

describe("FormField", () => {
  it("nối label, hint, lỗi với control", () => {
    render(
      <FormField label="Email" required hint="Gợi ý" error="Email không hợp lệ">
        <TextInput />
      </FormField>,
    );
    const input = screen.getByLabelText(/Email/);
    expect(input).toHaveAttribute("aria-invalid", "true");
    expect(input).toHaveAccessibleDescription("Gợi ý Email không hợp lệ");
  });

  it("không có lỗi thì không aria-invalid", () => {
    render(
      <FormField label="Tên">
        <TextInput />
      </FormField>,
    );
    expect(screen.getByLabelText("Tên")).not.toHaveAttribute("aria-invalid");
  });
});
