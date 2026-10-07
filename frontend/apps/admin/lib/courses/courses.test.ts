import { describe, expect, it, vi } from "vitest";
import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { classifyCourseFormError, courseActionError, teacherAssignError, normalizeErrorKey } from "./errors";
import {
  buildCreateFormData,
  buildUpdateRequest,
  descriptionToHtml,
  validateCourseForm,
  type CourseFormValues,
  type FormRules,
} from "./form";
import { checkImageDimensions, checkImageFile, sniffImageType } from "./image";
import { courseQueryToApi, courseQueryToSearch, parseCourseQuery } from "./query";
import type { CourseDetail } from "./types";
import { parseManualOrder } from "@/components/courses/ManualOrderField";
import { isCourseStaff } from "./permissions";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));

const params = (s: string) => new URLSearchParams(s);
const err = (status: number, body: ConstructorParameters<typeof ApiError>[1]) => new ApiError(status, body);

describe("query URL", () => {
  it("mặc định khi trống", () => {
    expect(parseCourseQuery(params(""))).toEqual({ q: "", status: "", gradeLevel: null, subjectId: null, teacherId: null, page: 1, perPage: 25 });
  });
  it("loại giá trị lạ, không bao giờ gửi tham số sai lên API", () => {
    const q = parseCourseQuery(params("status=zz&grade_level=5&subject_id=abc&teacher_id=-1&page=0&per_page=7"));
    expect(q).toEqual({ q: "", status: "", gradeLevel: null, subjectId: null, teacherId: null, page: 1, perPage: 25 });
    expect(parseCourseQuery(params("grade_level=13")).gradeLevel).toBeNull();
  });
  it("nhận giá trị hợp lệ và quay lại đúng URL", () => {
    const q = parseCourseQuery(params("q=%20Đại%20số&status=draft&grade_level=9&subject_id=3&teacher_id=4&per_page=50&page=2"));
    expect(q).toEqual({ q: "Đại số", status: "draft", gradeLevel: 9, subjectId: 3, teacherId: 4, page: 2, perPage: 50 });
    expect(courseQueryToSearch(q)).toBe("?q=%C4%90%E1%BA%A1i+s%E1%BB%91&status=draft&grade_level=9&subject_id=3&teacher_id=4&per_page=50&page=2");
    expect(courseQueryToSearch(parseCourseQuery(params("")))).toBe("");
  });
  it("giáo viên không gửi teacher_id lên API", () => {
    const q = parseCourseQuery(params("teacher_id=4&page=2"));
    expect(courseQueryToApi(q, { isStaff: true })).toBe("teacher_id=4&per_page=25&page=2");
    expect(courseQueryToApi(q, { isStaff: false })).toBe("per_page=25&page=2");
  });
});

const valid: CourseFormValues = {
  title: "  Hình học   lớp 9 ",
  gradeLevel: "9",
  subjectIds: [1, 2],
  shortDescription: "",
  description: "Dòng 1\nDòng 2\n\nĐoạn 2 & <b",
  price: "500000",
  teacherIds: [7],
  thumbnail: new File([new Uint8Array([0xff, 0xd8, 0xff, 0xe0])], "a.jpg", { type: "image/jpeg" }),
};
const staffCreate: FormRules = { mode: "create", isStaff: true, editPrice: true, editGradeLevel: true, hasThumbnail: false };

describe("validateCourseForm", () => {
  it("hợp lệ", () => expect(validateCourseForm(valid, staffCreate)).toEqual({}));
  it("trường bắt buộc khi tạo", () => {
    const e = validateCourseForm({ ...valid, title: " ", gradeLevel: "", subjectIds: [], description: "  ", price: "", teacherIds: [], thumbnail: null }, staffCreate);
    expect(Object.keys(e).sort()).toEqual(["description", "grade_level", "price", "subject_ids", "teacher_ids", "thumbnail", "title"]);
  });
  it("title có < > bị chặn (server cũng 422), quá dài, giá ngoài biên", () => {
    expect(validateCourseForm({ ...valid, title: "a < b" }, staffCreate).title).toMatch(/< hoặc >/);
    expect(validateCourseForm({ ...valid, title: "a".repeat(256) }, staffCreate).title).toMatch(/255/);
    expect(validateCourseForm({ ...valid, price: "50000001" }, staffCreate).price).toMatch(/50\.000\.000/);
    expect(validateCourseForm({ ...valid, price: "12.5" }, staffCreate).price).toMatch(/số nguyên/);
    expect(validateCourseForm({ ...valid, price: "1e3" }, staffCreate).price).toMatch(/số nguyên/);
    expect(validateCourseForm({ ...valid, price: "0" }, staffCreate).price).toBeUndefined();
  });
  it("giáo viên tạo: không bắt chọn giáo viên; sửa: không bắt ảnh mới khi đã có ảnh", () => {
    expect(validateCourseForm({ ...valid, teacherIds: [] }, { ...staffCreate, isStaff: false }).teacher_ids).toBeUndefined();
    expect(validateCourseForm({ ...valid, thumbnail: null }, { mode: "edit", isStaff: true, editPrice: true, editGradeLevel: true, hasThumbnail: true }).thumbnail).toBeUndefined();
  });
  it("trường bị ẩn theo abilities không bị kiểm", () => {
    const e = validateCourseForm({ ...valid, price: "", gradeLevel: "" }, { mode: "edit", isStaff: false, editPrice: false, editGradeLevel: false, hasThumbnail: true });
    expect(e).toEqual({});
  });
});

describe("descriptionToHtml", () => {
  it("văn bản thường → <p>, escape ký tự đặc biệt, xuống dòng → <br>", () => {
    expect(descriptionToHtml("A & B\nC\n\nD < E")).toBe("<p>A &amp; B<br>C</p><p>D &lt; E</p>");
  });
  it("có thẻ HTML thì giữ nguyên (server lọc)", () => {
    expect(descriptionToHtml("  <p>x</p>  ")).toBe("<p>x</p>");
  });
  it("'<' không tạo thành thẻ hoàn chỉnh vẫn là văn bản thường", () => {
    expect(descriptionToHtml("a <b và 3 < 4")).toBe("<p>a &lt;b và 3 &lt; 4</p>");
    expect(descriptionToHtml("Xin <strong>chào</strong>")).toBe("Xin <strong>chào</strong>");
  });
  it("rỗng", () => expect(descriptionToHtml("  \n ")).toBe(""));
});

describe("FormData khi tạo", () => {
  it("staff gửi teacher_ids[], subject_ids[], thumbnail; giáo viên thì không gửi teacher_ids", () => {
    const f = buildCreateFormData(valid, { isStaff: true });
    expect(f.get("title")).toBe("Hình học lớp 9");
    expect(f.getAll("subject_ids[]")).toEqual(["1", "2"]);
    expect(f.getAll("teacher_ids[]")).toEqual(["7"]);
    expect(f.get("price")).toBe("500000");
    expect(f.get("thumbnail")).toBeInstanceOf(File);
    expect(f.has("short_description")).toBe(false);
    expect(buildCreateFormData(valid, { isStaff: false }).has("teacher_ids[]")).toBe(false);
  });
});

const course = (over: Partial<CourseDetail> = {}): CourseDetail => ({
  id: 5,
  title: "Cũ",
  slug: "cu",
  short_description: null,
  grade_level: 9,
  price: 100000,
  thumbnail_url: "http://x/y.webp",
  status: "draft",
  published_at: null,
  enrollments_count: 0,
  subjects: [{ id: 1, name: "A", slug: "a" }],
  teachers: [{ id: 7, name: "GV" }],
  created_by: 1,
  created_at: null,
  updated_at: null,
  description: "<p>Mô tả</p>",
  abilities: { update: true, delete: true, publish: true, manage_teachers: true, edit_price: true, edit_grade_level: true },
  ...over,
});
const same = (c: CourseDetail): CourseFormValues => ({
  title: c.title,
  gradeLevel: String(c.grade_level),
  subjectIds: [1],
  shortDescription: "",
  description: c.description,
  price: String(c.price),
  teacherIds: [7],
  thumbnail: null,
});

describe("buildUpdateRequest", () => {
  it("không đổi gì → none", () => expect(buildUpdateRequest(course(), same(course()))).toEqual({ kind: "none" }));
  it("chỉ gửi trường đã đổi (JSON PUT), không gửi teacher_ids", () => {
    const c = course();
    const req = buildUpdateRequest(c, { ...same(c), title: " Mới ", price: "0", subjectIds: [2, 1] });
    expect(req).toEqual({ kind: "json", body: { title: "Mới", price: 0, subject_ids: [2, 1] } });
  });
  it("xoá mô tả ngắn → null", () => {
    const c = course({ short_description: "x" });
    expect(buildUpdateRequest(c, same(c))).toEqual({ kind: "json", body: { short_description: null } });
  });
  it("có ảnh mới → multipart với _method=PUT", () => {
    const c = course();
    const req = buildUpdateRequest(c, { ...same(c), title: "Mới", subjectIds: [1, 3], thumbnail: valid.thumbnail });
    expect(req.kind).toBe("multipart");
    if (req.kind !== "multipart") return;
    expect(req.form.get("_method")).toBe("PUT");
    expect(req.form.get("title")).toBe("Mới");
    expect(req.form.getAll("subject_ids[]")).toEqual(["1", "3"]);
    expect(req.form.get("thumbnail")).toBeInstanceOf(File);
    expect(req.form.has("price")).toBe(false);
  });
  it("giáo viên (abilities ẩn giá/lớp) không bao giờ gửi price, grade_level", () => {
    const c = course({ abilities: { update: true, delete: false, publish: false, manage_teachers: false, edit_price: false, edit_grade_level: false } });
    const req = buildUpdateRequest(c, { ...same(c), price: "1", gradeLevel: "12", title: "T" });
    expect(req).toEqual({ kind: "json", body: { title: "T" } });
  });
});

describe("lỗi API", () => {
  it("422 subject_ids.0 và teacher_ids hiện dưới field cha", () => {
    const f = classifyCourseFormError(
      err(422, { message: "x", errors: { "subject_ids.0": ["Chuyên đề không tồn tại hoặc đang ẩn."], teacher_ids: ["Giáo viên bị khoá."], title: ["Trùng"] } }),
      { hadFile: false },
    );
    expect(f.fields).toEqual({ subject_ids: "Chuyên đề không tồn tại hoặc đang ẩn.", teacher_ids: "Giáo viên bị khoá.", title: "Trùng" });
    expect(f.banner).toBeNull();
    expect(normalizeErrorKey("teacher_ids.12")).toBe("teacher_ids");
  });
  it("422 field lạ → banner; 422 thumbnail tiếng Anh → câu chung", () => {
    expect(classifyCourseFormError(err(422, { message: "x", errors: { other: ["Lạ"] } }), { hadFile: false }).banner).toBe("Lạ");
    expect(classifyCourseFormError(err(422, { message: "x", errors: { thumbnail: ["The thumbnail field must be a file."] } }), { hadFile: true }).fields.thumbnail).toMatch(/JPG\/PNG\/WebP/);
  });
  it("413 (Nginx trả HTML, api-client dựng ApiError chung) → lỗi ở ô ảnh", () => {
    const f = classifyCourseFormError(err(413, { message: "Đã có lỗi xảy ra, vui lòng thử lại sau." }), { hadFile: true });
    expect(f.fields.thumbnail).toMatch(/2 MB/);
    expect(f.banner).toBeNull();
  });
  it("lỗi mạng có ảnh kèm gợi ý dung lượng (413 không có CORS)", () => {
    expect(classifyCourseFormError(new NetworkError(new Error("x")), { hadFile: true }).banner).toMatch(/2 MB/);
    expect(classifyCourseFormError(new NetworkError(new Error("x")), { hadFile: false }).banner).not.toMatch(/2 MB/);
  });
  it("403 và 404", () => {
    expect(classifyCourseFormError(err(403, { message: "m", code: "FORBIDDEN" }), { hadFile: false }).banner).toMatch(/không có quyền/);
    const gone = classifyCourseFormError(err(404, { message: "m" }), { hadFile: false });
    expect(gone.gone).toBe(true);
  });
  it("thông điệp thao tác theo mã lỗi", () => {
    expect(courseActionError(err(422, { message: "m", code: "COURSE_NOT_PUBLISHABLE" }))).toMatch(/ít nhất 1 chương và 1 bài học/);
    expect(courseActionError(err(409, { message: "m", code: "COURSE_HAS_ENROLLMENTS" }))).toMatch(/Ngừng bán/);
    expect(courseActionError(err(409, { message: "m", code: "ALREADY_PROCESSED" }))).toMatch(/đã thay đổi/);
    expect(courseActionError(err(409, { message: "m", code: "INVALID_COURSE_STATE" }))).toMatch(/đã thay đổi/);
  });
  it("gán giáo viên: lỗi ở errors.teacher_ids", () => {
    expect(teacherAssignError(err(422, { message: "m", errors: { teacher_ids: ["Chỉ được gán giáo viên đang hoạt động."] } }))).toBe("Chỉ được gán giáo viên đang hoạt động.");
  });
});

describe("kiểm tra ảnh ở client", () => {
  const file = (bytes: number[], name = "a.jpg", size?: number) => {
    const f = new File([new Uint8Array(bytes)], name);
    if (size !== undefined) Object.defineProperty(f, "size", { value: size });
    return f;
  };
  it("nhận diện theo byte đầu", () => {
    expect(sniffImageType(new Uint8Array([0xff, 0xd8, 0xff, 0xe0]))).toBe("jpeg");
    expect(sniffImageType(new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]))).toBe("png");
    expect(sniffImageType(new TextEncoder().encode("RIFF\0\0\0\0WEBPVP8 "))).toBe("webp");
    expect(sniffImageType(new TextEncoder().encode("GIF89a......"))).toBeNull();
    expect(sniffImageType(new TextEncoder().encode("<svg xmlns"))).toBeNull();
  });
  it("file đổi đuôi (.jpg nhưng là SVG/HTML) bị từ chối", async () => {
    expect(await checkImageFile(file(Array.from(new TextEncoder().encode("<svg><script>"))))).toMatch(/không nhận SVG hoặc GIF/);
  });
  it("quá 2 MB, rỗng", async () => {
    expect(await checkImageFile(file([0xff, 0xd8, 0xff], "a.jpg", 2 * 1024 * 1024 + 1))).toMatch(/2 MB/);
    expect(await checkImageFile(file([]))).toMatch(/JPG\/PNG\/WebP/);
  });
  it("ảnh hợp lệ; kích thước tối đa 4000", async () => {
    expect(await checkImageFile(file([0xff, 0xd8, 0xff, 0xe0, 0, 0]))).toBeNull();
    expect(checkImageDimensions(4000, 4000)).toBeNull();
    expect(checkImageDimensions(4001, 10)).toMatch(/4000/);
  });
});

describe("thứ tự hiển thị và quyền", () => {
  it("parseManualOrder", () => {
    expect(parseManualOrder("")).toBeNull();
    expect(parseManualOrder(" 0 ")).toBe(0);
    expect(parseManualOrder("1000000")).toBe(1_000_000);
    expect(parseManualOrder("1000001")).toBeUndefined();
    expect(parseManualOrder("-1")).toBeUndefined();
    expect(parseManualOrder("1.5")).toBeUndefined();
  });
  it("isCourseStaff ưu tiên permissions, rơi về vai trò", () => {
    expect(isCourseStaff({ role: "giao_vien", permissions: null })).toBe(false);
    expect(isCourseStaff({ role: "quan_ly_trang", permissions: null })).toBe(true);
    expect(isCourseStaff({ role: "giao_vien", permissions: { manage_all_courses: true } })).toBe(true);
  });
});
