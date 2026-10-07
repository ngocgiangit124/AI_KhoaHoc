import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@vitaminvui/api-client";
import * as api from "@/lib/courses/api";
import type { CourseDetail } from "@/lib/courses/types";
import { CourseForm } from "./CourseForm";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));
vi.mock("@/lib/courses/api");

const JPEG = [0xff, 0xd8, 0xff, 0xe0, 0, 0x10, 0x4a, 0x46];
const jpegFile = () => new File([new Uint8Array(JPEG)], "bia.jpg", { type: "image/jpeg" });

class FakeImage {
  naturalWidth = 800;
  naturalHeight = 600;
  onload: (() => void) | null = null;
  onerror: (() => void) | null = null;
  set src(_: string) {
    queueMicrotask(() => this.onload?.());
  }
}

beforeEach(() => {
  vi.resetAllMocks();
  vi.stubGlobal("Image", FakeImage);
  vi.mocked(api.listActiveSubjects).mockResolvedValue([
    { id: 1, name: "Đại số" },
    { id: 2, name: "Hình học" },
  ]);
  vi.mocked(api.listTeachers).mockResolvedValue([{ id: 7, name: "Cô Lan" }]);
});
afterEach(() => vi.unstubAllGlobals());

const ui = () => userEvent.setup({ applyAccept: false });

async function fillValid(user: ReturnType<typeof ui>) {
  await user.type(screen.getByLabelText(/Tên khóa học/), "Hình học lớp 9");
  await user.selectOptions(screen.getByLabelText(/^Lớp/), "9");
  await user.click(await screen.findByRole("checkbox", { name: "Đại số" }));
  await user.type(screen.getByLabelText(/Mô tả chi tiết/), "Nội dung");
  await user.type(screen.getByLabelText(/Học phí/), "100000");
  await screen.findByRole("option", { name: "Cô Lan" });
  await user.selectOptions(screen.getByLabelText("Thêm giáo viên"), "7");
  expect(screen.getByRole("button", { name: "Bỏ Cô Lan" })).toBeInTheDocument();
  await user.upload(screen.getByLabelText(/Chọn ảnh bìa/), jpegFile());
  await screen.findByAltText("Xem trước ảnh bìa mới");
}

describe("CourseForm (tạo)", () => {
  it("gửi rỗng: lỗi dưới từng field, không gọi API", async () => {
    const user = ui();
    render(<CourseForm mode="create" isStaff onSaved={vi.fn()} />);
    await user.click(screen.getByRole("button", { name: "Tạo khóa học (nháp)" }));
    expect(await screen.findByText("Vui lòng nhập tên khóa học.")).toBeInTheDocument();
    expect(screen.getByText("Vui lòng chọn lớp (6–12).")).toBeInTheDocument();
    expect(screen.getByText("Vui lòng chọn ít nhất 1 chuyên đề.")).toBeInTheDocument();
    expect(screen.getByText("Vui lòng nhập mô tả khóa học.")).toBeInTheDocument();
    expect(screen.getByText("Vui lòng nhập học phí.")).toBeInTheDocument();
    expect(screen.getByText("Khóa học cần tối thiểu 1 giáo viên phụ trách.")).toBeInTheDocument();
    expect(screen.getByText("Vui lòng chọn ảnh bìa.")).toBeInTheDocument();
    expect(api.createCourse).not.toHaveBeenCalled();
  });

  it("ảnh SVG đổi đuôi .jpg bị từ chối ngay ở client; ảnh > 2 MB báo dung lượng", async () => {
    const user = ui();
    render(<CourseForm mode="create" isStaff onSaved={vi.fn()} />);
    const input = screen.getByLabelText(/Chọn ảnh bìa/);
    await user.upload(input, new File(["<svg xmlns='http://www.w3.org/2000/svg'></svg>"], "x.jpg", { type: "image/jpeg" }));
    expect(await screen.findByText("Ảnh phải là JPG/PNG/WebP, không nhận SVG hoặc GIF.")).toBeInTheDocument();
    const big = new File([new Uint8Array(JPEG)], "big.jpg", { type: "image/jpeg" });
    Object.defineProperty(big, "size", { value: 3 * 1024 * 1024 });
    await user.upload(input, big);
    expect(await screen.findByText("Ảnh tối đa 2 MB.")).toBeInTheDocument();
    expect(screen.queryByAltText("Xem trước ảnh bìa mới")).toBeNull();
  });

  it("422: subject_ids.0 và teacher_ids hiện dưới nhóm chọn, giữ nguyên dữ liệu; gửi lại được", async () => {
    const user = ui();
    vi.mocked(api.createCourse)
      .mockRejectedValueOnce(
        new ApiError(422, { message: "x", errors: { "subject_ids.0": ["Chuyên đề không tồn tại hoặc đang ẩn."], teacher_ids: ["Giáo viên bị khoá."] } }),
      )
      .mockResolvedValueOnce({ id: 9 } as CourseDetail);
    const onSaved = vi.fn();
    render(<CourseForm mode="create" isStaff onSaved={onSaved} />);
    await fillValid(user);
    await user.click(screen.getByRole("button", { name: "Tạo khóa học (nháp)" }));
    expect(await screen.findByText("Chuyên đề không tồn tại hoặc đang ẩn.")).toBeInTheDocument();
    expect(screen.getByText("Giáo viên bị khoá.")).toBeInTheDocument();
    expect(screen.getByLabelText(/Tên khóa học/)).toHaveValue("Hình học lớp 9");
    await user.click(screen.getByRole("button", { name: "Tạo khóa học (nháp)" }));
    await waitFor(() => expect(onSaved).toHaveBeenCalledWith({ id: 9 }));
    const form = vi.mocked(api.createCourse).mock.calls[1]![0];
    expect(form.getAll("subject_ids[]")).toEqual(["1"]);
    expect(form.getAll("teacher_ids[]")).toEqual(["7"]);
    expect(form.get("thumbnail")).toBeInstanceOf(File);
  });

  it("413 (Nginx HTML) → lỗi dưới ô ảnh, nút lưu mở lại", async () => {
    const user = ui();
    vi.mocked(api.createCourse).mockRejectedValue(new ApiError(413, { message: "Đã có lỗi xảy ra, vui lòng thử lại sau." }));
    render(<CourseForm mode="create" isStaff onSaved={vi.fn()} />);
    await fillValid(user);
    await user.click(screen.getByRole("button", { name: "Tạo khóa học (nháp)" }));
    expect(await screen.findByText(/Tệp tải lên quá lớn/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Tạo khóa học (nháp)" })).toBeEnabled();
  });

  it("giáo viên: không có ô chọn giáo viên, không gửi teacher_ids", async () => {
    const user = ui();
    vi.mocked(api.createCourse).mockResolvedValue({ id: 3 } as CourseDetail);
    render(<CourseForm mode="create" isStaff={false} onSaved={vi.fn()} />);
    expect(screen.queryByTestId("teachers-group")).toBeNull();
    expect(screen.getByText(/tự động là giáo viên phụ trách/)).toBeInTheDocument();
    await user.type(screen.getByLabelText(/Tên khóa học/), "A");
    await user.selectOptions(screen.getByLabelText(/^Lớp/), "6");
    await user.click(await screen.findByRole("checkbox", { name: "Đại số" }));
    await user.type(screen.getByLabelText(/Mô tả chi tiết/), "B");
    await user.click(screen.getByLabelText("Khóa học miễn phí"));
    await user.upload(screen.getByLabelText(/Chọn ảnh bìa/), jpegFile());
    await screen.findByAltText("Xem trước ảnh bìa mới");
    await user.click(screen.getByRole("button", { name: "Tạo khóa học (nháp)" }));
    await waitFor(() => expect(api.createCourse).toHaveBeenCalled());
    const form = vi.mocked(api.createCourse).mock.calls[0]![0];
    expect(form.has("teacher_ids[]")).toBe(false);
    expect(form.get("price")).toBe("0");
    expect(api.listTeachers).not.toHaveBeenCalled();
  });
});

const detail = (over: Partial<CourseDetail["abilities"]> = {}): CourseDetail => ({
  id: 5,
  title: "Cũ",
  slug: "cu",
  short_description: null,
  grade_level: 9,
  price: 100000,
  thumbnail_url: null,
  status: "published",
  published_at: "2026-01-01T00:00:00+07:00",
  enrollments_count: 0,
  subjects: [{ id: 3, name: "Đã ẩn", slug: "da-an" }],
  teachers: [{ id: 7, name: "Cô Lan" }],
  created_by: 1,
  created_at: null,
  updated_at: null,
  description: "<p>Mô tả</p>",
  abilities: { update: true, delete: false, publish: false, manage_teachers: false, edit_price: false, edit_grade_level: false, ...over },
});

describe("CourseForm (sửa)", () => {
  it("giáo viên (abilities): ô giá và lớp chỉ đọc; không có thay đổi → báo, không gọi API", async () => {
    const user = ui();
    render(<CourseForm mode="edit" course={{ ...detail(), thumbnail_url: "http://x/a.webp" }} isStaff={false} onSaved={vi.fn()} />);
    expect(screen.getByLabelText(/Học phí/)).toBeDisabled();
    expect(screen.getByLabelText(/^Lớp/)).toBeDisabled();
    expect(screen.getByText(/Khóa đã xuất bản: chỉ Admin\/Quản lý trang đổi được lớp/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Lưu thay đổi" }));
    expect(await screen.findByText("Chưa có thay đổi nào để lưu.")).toBeInTheDocument();
    expect(api.updateCourse).not.toHaveBeenCalled();
  });

  it("chuyên đề đã gán nhưng đang ẩn vẫn hiện (đánh dấu) và không bị gửi lại nếu không đổi", async () => {
    const user = ui();
    vi.mocked(api.updateCourse).mockResolvedValue({ ...detail(), title: "Mới" });
    render(<CourseForm mode="edit" course={{ ...detail(), thumbnail_url: "http://x/a.webp" }} isStaff={false} onSaved={vi.fn()} />);
    expect(await screen.findByRole("checkbox", { name: /Đã ẩn/ })).toBeChecked();
    await user.type(screen.getByLabelText(/Tên khóa học/), " 2");
    await user.click(screen.getByRole("button", { name: "Lưu thay đổi" }));
    await waitFor(() => expect(api.updateCourse).toHaveBeenCalled());
    expect(vi.mocked(api.updateCourse).mock.calls[0]).toEqual([5, { kind: "json", body: { title: "Cũ 2" } }]);
  });

  it("404 khi lưu: báo khóa học không còn tồn tại và gọi onGone", async () => {
    const user = ui();
    vi.mocked(api.updateCourse).mockRejectedValue(new ApiError(404, { message: "x" }));
    const onGone = vi.fn();
    render(<CourseForm mode="edit" course={{ ...detail(), thumbnail_url: "http://x/a.webp" }} isStaff={false} onSaved={vi.fn()} onGone={onGone} />);
    await user.type(screen.getByLabelText(/Tên khóa học/), "x");
    await user.click(screen.getByRole("button", { name: "Lưu thay đổi" }));
    expect(await screen.findByText(/Khóa học không còn tồn tại/)).toBeInTheDocument();
    expect(onGone).toHaveBeenCalled();
  });
});
