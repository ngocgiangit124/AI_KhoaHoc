"use client";

import { useState, type FormEvent } from "react";
import {
  Badge,
  Button,
  Checkbox,
  CourseCover,
  Field,
  IconButton,
  IconImage,
  IconX,
  Select,
  TextInput,
  Textarea,
  useToast,
} from "@vitaminvui/ui/v2";
import { COURSE_DESCRIPTION, SUBJECTS, TEACHERS, type AdminCourse, type StaffRole } from "@/lib/mock/v2/data";

/**
 * Tab "Thông tin chung" (US-009 §2.2, PUT /admin/courses/{id}). Desktop 2 cột: trái nội dung, phải ảnh + giáo viên.
 * Quyền ẩn/hiện theo `abilities` của CourseResource: GV không sửa giá/giáo viên; lớp chỉ sửa khi chưa từng xuất bản.
 * TODO(dev): ảnh gửi multipart `_method=PUT`; mô tả chi tiết dùng trình soạn rich text (HTML được lọc ở server).
 */
export function CourseInfoForm({ course, role, mode = "edit" }: { course: AdminCourse; role: StaffRole; mode?: "edit" | "create" }) {
  const creating = mode === "create";
  const toast = useToast();
  const isStaff = role !== "giao_vien";
  const [free, setFree] = useState(course.price === 0);
  const [teachers, setTeachers] = useState(course.teachers);
  const [short, setShort] = useState(creating ? "" : "Góc với đường tròn, tứ giác nội tiếp, độ dài cung và diện tích hình quạt — đủ cho bài thi vào 10.");
  const [saving, setSaving] = useState(false);
  const [teacherError, setTeacherError] = useState<string>();
  const gradeLocked = !isStaff && course.published_at !== null;

  function onSubmit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setTimeout(() => {
      setSaving(false);
      toast.show(creating ? { tone: "success", title: "Đã tạo khóa học (nháp)", description: "Tiếp theo: thêm chương và bài học." } : { tone: "success", title: "Đã lưu thay đổi" });
    }, 800);
  }

  return (
    <form onSubmit={onSubmit} noValidate className="grid gap-6 lg:grid-cols-[1fr_340px]">
      <div className="flex flex-col gap-5 rounded-card border border-line bg-surface p-5">
        <Field
          label="Tên khóa học"
          required
          hint={creating ? "Đường dẫn tự sinh từ tên khi lưu và không đổi về sau." : `Đường dẫn: /khoa-hoc/${course.slug} (không đổi khi sửa tên)`}
        >
          <TextInput size="sm" defaultValue={course.title} maxLength={255} placeholder={creating ? "Ví dụ: Hình học 9: Đường tròn" : undefined} />
        </Field>
        <div className="grid gap-5 sm:grid-cols-2">
          <Field label="Lớp" required hint={gradeLocked ? "Khóa đã xuất bản: chỉ Admin/Quản lý trang đổi được lớp." : undefined}>
            <Select size="sm" defaultValue={course.grade_level} disabled={gradeLocked}>
              {[6, 7, 8, 9, 10, 11, 12].map((g) => (
                <option key={g} value={g}>
                  Lớp {g}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Học phí (đồng)" required hint={isStaff ? "Nhập 0 cho khóa miễn phí (học sinh xin học, giáo viên duyệt)." : "Chỉ Admin/Quản lý trang sửa được học phí."}>
            <TextInput size="sm" inputMode="numeric" defaultValue={free ? "0" : creating ? "" : String(course.price)} disabled={!isStaff || free} className="num" placeholder={creating ? "Ví dụ: 399000" : undefined} />
          </Field>
        </div>
        {isStaff ? <Checkbox id="free" label="Khóa học miễn phí" checked={free} onChange={(e) => setFree(e.target.checked)} /> : null}
        <fieldset>
          <legend className="mb-1 text-sm font-semibold text-ink">
            Chuyên đề <span className="text-danger" aria-hidden="true">*</span>
            <span className="sr-only"> (bắt buộc, chọn 1–20)</span>
          </legend>
          <div className="grid gap-x-4 sm:grid-cols-2">
            {SUBJECTS.map((s) => (
              <Checkbox key={s.id} id={`subject-${s.id}`} label={s.name} defaultChecked={course.subjects.some((x) => x.id === s.id)} className="min-h-10 py-1.5" />
            ))}
          </div>
        </fieldset>
        <Field label="Mô tả ngắn" hint="Hiện trên thẻ khóa học ở danh mục." aside={<span className="num text-ink-soft">{short.length}/500</span>}>
          <Textarea rows={3} maxLength={500} value={short} onChange={(e) => setShort(e.target.value)} className="text-sm" />
        </Field>
        <Field label="Mô tả chi tiết" required hint="Trình soạn thảo định dạng (đậm, danh sách, liên kết) đặt ở đây; HTML được lọc an toàn khi lưu.">
          <Textarea rows={6} defaultValue={creating ? "" : COURSE_DESCRIPTION} className="text-sm" />
        </Field>
      </div>

      <div className="flex flex-col gap-6">
        <section aria-labelledby="anh-bia" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-5">
          <h2 id="anh-bia" className="text-base font-semibold text-ink">
            Ảnh bìa <span className="text-danger" aria-hidden="true">*</span>
          </h2>
          {creating ? (
            <div className="flex aspect-video flex-col items-center justify-center gap-1 rounded-control border border-dashed border-line-strong bg-sunken text-sm text-ink-soft">
              <IconImage />
              Chưa có ảnh bìa (bắt buộc khi tạo)
            </div>
          ) : (
            <div className="overflow-hidden rounded-control border border-line">
              <CourseCover title={course.title} gradeLevel={course.grade_level} subjectSlug={course.subjects[0]?.slug} />
            </div>
          )}
          <label className="focus-ring flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-control border border-dashed border-line-strong px-3 text-sm font-semibold text-primary hover:bg-primary-soft has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-focus">
            <IconImage size={18} />
            {creating ? "Chọn ảnh bìa" : "Chọn ảnh khác"}
            <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" />
          </label>
          <p className="text-sm text-ink-soft">JPG, PNG hoặc WebP, tối đa 2MB, tỉ lệ 16:9. Không nhận SVG/GIF. Ảnh được nén lại thành WebP khi lưu.</p>
        </section>

        {isStaff ? (
          <section aria-labelledby="giao-vien" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-5">
            <h2 id="giao-vien" className="text-base font-semibold text-ink">
              Giáo viên phụ trách <span className="text-danger" aria-hidden="true">*</span>
            </h2>
            <ul className="flex flex-wrap gap-2">
              {teachers.map((t) => (
                <li key={t.id} className="inline-flex items-center gap-1 rounded-full bg-primary-soft py-0.5 pl-3 pr-0.5 text-sm font-semibold text-primary">
                  {t.name}
                  <IconButton
                    size="sm"
                    label={`Bỏ ${t.name}`}
                    icon={<IconX size={14} />}
                    className="size-7"
                    onClick={() => {
                      if (teachers.length === 1) {
                        setTeacherError("Khóa học cần có ít nhất 1 giáo viên phụ trách.");
                        return;
                      }
                      setTeacherError(undefined);
                      setTeachers(teachers.filter((x) => x.id !== t.id));
                    }}
                  />
                </li>
              ))}
            </ul>
            {teacherError ? <p className="text-sm font-medium text-danger">{teacherError}</p> : null}
            <Field label="Thêm giáo viên">
              <Select
                size="sm"
                value=""
                onChange={(e) => {
                  const t = TEACHERS.find((x) => x.id === Number(e.target.value));
                  if (t && !teachers.some((x) => x.id === t.id)) setTeachers([...teachers, t]);
                  setTeacherError(undefined);
                }}
              >
                <option value="">Chọn giáo viên…</option>
                {TEACHERS.filter((t) => !teachers.some((x) => x.id === t.id)).map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.name}
                  </option>
                ))}
              </Select>
            </Field>
          </section>
        ) : (
          <section aria-labelledby="giao-vien" className="rounded-card border border-line bg-surface p-5">
            <h2 id="giao-vien" className="text-base font-semibold text-ink">
              Giáo viên phụ trách
            </h2>
            <p className="mt-2 text-sm text-ink">{creating ? "Bạn (tự động là giáo viên phụ trách)" : course.teachers.map((t) => t.name).join(", ")}</p>
            <p className="mt-1 text-sm text-ink-soft">Admin/Quản lý trang phân công thêm giáo viên.</p>
          </section>
        )}

        {creating ? (
          <p className="rounded-card border border-line bg-surface p-5 text-sm text-ink-soft">
            Khóa mới ở trạng thái <strong className="text-ink">Nháp</strong>. Sau khi lưu, thêm chương và bài học; cần ít nhất 1 chương và 1 bài để xuất bản.
            {!isStaff ? " Admin sẽ xem xét và xuất bản." : ""}
          </p>
        ) : (
        <section aria-labelledby="hien-thi" className="flex flex-col gap-2 rounded-card border border-line bg-surface p-5 text-sm">
          <h2 id="hien-thi" className="text-base font-semibold text-ink">
            Hiển thị
          </h2>
          <p className="flex items-center justify-between gap-2 text-ink-soft">
            Học sinh đã đăng ký <span className="num font-semibold text-ink">{course.enrollments_count.toLocaleString("vi-VN")}</span>
          </p>
          {isStaff ? (
            <Field label="Thứ tự nổi bật" hint="Số nhỏ hiện trước khi sắp xếp “Nổi bật”. Để trống = không nổi bật.">
              <TextInput size="sm" inputMode="numeric" defaultValue={course.manual_order ?? ""} className="num" />
            </Field>
          ) : null}
          {!isStaff ? <Badge tone="info">Khóa học sẽ được Admin xem xét và xuất bản</Badge> : null}
        </section>
        )}
      </div>

      {/* Thanh lưu dính đáy: luôn thấy nút Lưu khi form dài. */}
      <div className="sticky bottom-0 z-10 -mx-4 flex justify-end gap-3 border-t border-line bg-surface px-4 py-3 sm:-mx-6 sm:px-6 lg:col-span-2 lg:-mx-8 lg:px-8">
        <Button variant="secondary" type="reset" size="sm">
          Huỷ thay đổi
        </Button>
        <Button type="submit" size="sm" loading={saving} loadingText="Đang lưu…">
          {creating ? "Tạo khóa học (nháp)" : "Lưu thay đổi"}
        </Button>
      </div>
    </form>
  );
}
