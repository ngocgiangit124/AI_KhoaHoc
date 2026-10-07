"use client";

import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from "react";
import { Alert, Button, Checkbox, Field, IconAlertCircle, Select, TextInput, Textarea, formatPrice } from "@vitaminvui/ui/v2";
import { createCourse, updateCourse } from "@/lib/courses/api";
import { classifyCourseFormError } from "@/lib/courses/errors";
import {
  EMPTY_VALUES,
  SHORT_DESC_MAX,
  TEACHER_REQUIRED_MESSAGE,
  TITLE_MAX,
  buildCreateFormData,
  buildUpdateRequest,
  parsePrice,
  validateCourseForm,
  valuesFromCourse,
  type CourseFormValues,
  type FieldErrors,
  type FormRules,
} from "@/lib/courses/form";
import { GRADE_LEVELS, type CourseDetail } from "@/lib/courses/types";
import { TeacherPicker, type TeacherOption } from "./TeacherPicker";
import { ThumbnailField } from "./ThumbnailField";
import { useSubjectOptions, useTeacherOptions } from "./useCourseOptions";

export interface CourseFormProps {
  mode: "create" | "edit";
  /** Bắt buộc khi `mode="edit"`. */
  course?: CourseDetail;
  /** Staff (admin/quản lý trang): thấy chọn giáo viên khi tạo. Giáo viên thì tự được gán. */
  isStaff: boolean;
  onSaved: (course: CourseDetail | null) => void;
  /** Khóa học đã bị xoá từ nơi khác (404 khi lưu). */
  onGone?: () => void;
  /**
   * Thẻ phụ ở cột phải (màn sửa: giáo viên, hiển thị). Nằm trong `<form>` nên chỉ dùng nút `type="button"`
   * và không để Enter gửi form (xem `ManualOrderField`).
   */
  aside?: ReactNode;
  /** Tăng sau mỗi lần lưu thành công: form tự nạp lại giá trị từ `course` mà KHÔNG remount (giữ nguyên `aside`). */
  version?: number;
  /** Báo form chính có thay đổi chưa lưu (màn sửa dùng để nhắc trước khi xuất bản/ngừng bán). */
  onDirtyChange?: (dirty: boolean) => void;
}

const FORM_ID = "course-form";
const CARD = "flex flex-col gap-5 rounded-card border border-line bg-surface p-5";

/** Form tạo/sửa thông tin chung của khóa học (US-009 §2.2), hình thức theo `components/v2/CourseInfoForm`. Lỗi 422 hiện dưới đúng field, giữ nguyên dữ liệu đã nhập. */
export function CourseForm({ mode, course, isStaff, onSaved, onGone, aside, version = 0, onDirtyChange }: CourseFormProps) {
  const abilities = course?.abilities;
  const rules: FormRules = useMemo(
    () =>
      mode === "create"
        ? { mode, isStaff, editPrice: true, editGradeLevel: true, hasThumbnail: false }
        : {
            mode,
            isStaff,
            editPrice: abilities?.edit_price ?? false,
            editGradeLevel: abilities?.edit_grade_level ?? false,
            hasThumbnail: Boolean(course?.thumbnail_url),
          },
    [mode, isStaff, abilities, course?.thumbnail_url],
  );

  const [values, setValues] = useState<CourseFormValues>(() => (course ? valuesFromCourse(course) : EMPTY_VALUES));
  const [errors, setErrors] = useState<FieldErrors>({});
  const [banner, setBanner] = useState<{ tone: "danger" | "info"; text: string } | null>(null);
  const [pending, setPending] = useState(false);
  const [resetKey, setResetKey] = useState(0);
  const [seenVersion, setSeenVersion] = useState(version);
  // Nạp lại từ dữ liệu server sau khi lưu (điều chỉnh state ngay lúc render, không dùng effect).
  if (seenVersion !== version) {
    setSeenVersion(version);
    setValues(course ? valuesFromCourse(course) : EMPTY_VALUES);
    setErrors({});
    setBanner(null);
    setResetKey((n) => n + 1);
  }
  const pendingRef = useRef(false);
  const formRef = useRef<HTMLFormElement>(null);

  const subjects = useSubjectOptions(true, isStaff);
  const teachers = useTeacherOptions(mode === "create" && isStaff);

  const subjectOptions = useMemo(() => {
    const active = new Set(subjects.items.map((s) => s.id));
    // Chuyên đề đã gán nhưng nay đang ẩn: vẫn hiện để biết (API chỉ nhận chuyên đề đang hiển thị khi gửi lại danh sách).
    const hidden = (course?.subjects ?? []).filter((s) => !active.has(s.id)).map((s) => ({ id: s.id, name: s.name, note: "đang ẩn" }));
    return [...subjects.items.map((s) => ({ id: s.id, name: s.name, note: undefined as string | undefined })), ...hidden];
  }, [subjects.items, course?.subjects]);

  const teacherOptions: TeacherOption[] = useMemo(() => teachers.items.map((t) => ({ id: t.id, name: t.name })), [teachers.items]);
  const pickedTeachers: TeacherOption[] = values.teacherIds.map((id) => teacherOptions.find((t) => t.id === id) ?? { id, name: `Giáo viên #${id}` });

  const set = useCallback(<K extends keyof CourseFormValues>(key: K, value: CourseFormValues[K]) => {
    setValues((v) => ({ ...v, [key]: value }));
  }, []);
  const clear = useCallback((...keys: (keyof FieldErrors)[]) => {
    setErrors((e) => (keys.some((k) => e[k]) ? Object.fromEntries(Object.entries(e).filter(([k]) => !keys.includes(k as keyof FieldErrors))) : e));
  }, []);

  function focusFirstInvalid() {
    setTimeout(() => {
      const el = formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"],[data-invalid="true"]');
      if (!el) return;
      // Nhóm chọn (fieldset/div) không focus được → đưa focus vào control đầu tiên của nhóm.
      const focusable = el instanceof HTMLInputElement || el instanceof HTMLSelectElement || el instanceof HTMLTextAreaElement ? el : el.querySelector<HTMLElement>("input,select,button");
      focusable?.focus();
    }, 0);
  }

  // Cảnh báo khi rời trang với dữ liệu chưa lưu (tránh mất nội dung dài).
  const dirty = mode === "create" ? hasInput(values) : course ? buildUpdateRequest(course, values).kind !== "none" : false;
  useEffect(() => {
    onDirtyChange?.(dirty);
  }, [dirty, onDirtyChange]);
  useEffect(() => {
    if (!dirty) return;
    const handler = (e: BeforeUnloadEvent) => e.preventDefault();
    window.addEventListener("beforeunload", handler);
    return () => window.removeEventListener("beforeunload", handler);
  }, [dirty]);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (pendingRef.current) return;
    const invalid = validateCourseForm(values, rules);
    setErrors(invalid);
    setBanner(null);
    if (Object.keys(invalid).length > 0) {
      focusFirstInvalid();
      return;
    }

    let run: () => Promise<CourseDetail>;
    let hadFile = values.thumbnail !== null;
    if (mode === "create") {
      const form = buildCreateFormData(values, { isStaff });
      run = () => createCourse(form);
    } else {
      const req = buildUpdateRequest(course!, values);
      if (req.kind === "none") {
        setBanner({ tone: "info", text: "Chưa có thay đổi nào để lưu." });
        return;
      }
      hadFile = req.kind === "multipart";
      run = () => updateCourse(course!.id, req);
    }

    pendingRef.current = true;
    setPending(true);
    try {
      const saved = await run();
      onSaved(saved);
    } catch (err) {
      const failure = classifyCourseFormError(err, { hadFile });
      setErrors(failure.fields);
      setBanner(failure.banner ? { tone: "danger", text: failure.banner } : null);
      if (failure.gone) onGone?.();
      focusFirstInvalid();
    } finally {
      pendingRef.current = false;
      setPending(false);
    }
  }

  function reset() {
    setValues(course ? valuesFromCourse(course) : EMPTY_VALUES);
    setErrors({});
    setBanner(null);
    setResetKey((n) => n + 1);
  }

  const priceNumber = parsePrice(values.price);
  const isFree = values.price.trim() === "0";
  const gradeNumber = Number(values.gradeLevel) || course?.grade_level || 9;

  return (
    <form id={FORM_ID} ref={formRef} onSubmit={(e) => void onSubmit(e)} noValidate className="grid gap-6 lg:grid-cols-[1fr_340px]" aria-busy={pending}>
      {banner ? (
        <Alert tone={banner.tone} className="lg:col-span-2">
          {banner.text}
        </Alert>
      ) : null}

      <div className={CARD}>
        <Field
          label="Tên khóa học"
          required
          error={errors.title}
          hint={
            mode === "edit" && course
              ? `Đường dẫn: /khoa-hoc/${course.slug} (không đổi khi sửa tên). Văn bản thuần, không chứa ký tự < hoặc >.`
              : "Văn bản thuần, không chứa ký tự < hoặc >. Đường dẫn tự sinh từ tên khi lưu và không đổi về sau."
          }
        >
          <TextInput
            size="sm"
            className="max-sm:h-11"
            name="title"
            value={values.title}
            maxLength={TITLE_MAX + 20}
            autoComplete="off"
            disabled={pending}
            placeholder={mode === "create" ? "Ví dụ: Hình học 9: Đường tròn" : undefined}
            onChange={(e) => {
              set("title", e.target.value);
              clear("title");
            }}
          />
        </Field>

        <div className="grid gap-5 sm:grid-cols-2">
          <Field
            label="Lớp"
            required
            error={errors.grade_level}
            hint={!rules.editGradeLevel ? "Khóa đã xuất bản: chỉ Admin/Quản lý trang đổi được lớp." : undefined}
          >
            <Select
              size="sm"
              className="max-sm:h-11"
              name="grade_level"
              value={values.gradeLevel}
              disabled={pending || !rules.editGradeLevel}
              onChange={(e) => {
                set("gradeLevel", e.target.value);
                clear("grade_level");
              }}
            >
              <option value="">Chọn lớp</option>
              {GRADE_LEVELS.map((g) => (
                <option key={g} value={g}>
                  Lớp {g}
                </option>
              ))}
            </Select>
          </Field>

          <Field
            label="Học phí (đồng)"
            required
            error={errors.price}
            hint={
              !rules.editPrice
                ? "Chỉ Admin/Quản lý trang sửa được học phí."
                : priceNumber === null
                  ? "Nhập số nguyên từ 0 đến 50.000.000. Nhập 0 cho khóa miễn phí."
                  : formatPrice(priceNumber)
            }
          >
            <TextInput
              size="sm"
              name="price"
              inputMode="numeric"
              autoComplete="off"
              value={values.price}
              disabled={pending || !rules.editPrice}
              className="num max-sm:h-11"
              placeholder={mode === "create" ? "Ví dụ: 399000" : undefined}
              onChange={(e) => {
                set("price", e.target.value.replace(/[\s.,]/g, ""));
                clear("price");
              }}
            />
          </Field>
        </div>
        {rules.editPrice ? (
          <Checkbox
            id="free"
            label="Khóa học miễn phí"
            checked={isFree}
            disabled={pending}
            onChange={(e) => {
              set("price", e.target.checked ? "0" : "");
              clear("price");
            }}
          />
        ) : null}

        <fieldset
          data-testid="subjects-group"
          aria-invalid={errors.subject_ids ? true : undefined}
          aria-describedby={errors.subject_ids ? "subjects-error" : undefined}
          disabled={pending}
        >
          <legend className="mb-1 text-sm font-semibold text-ink">
            Chuyên đề{" "}
            <span className="text-danger" aria-hidden="true">
              *
            </span>
            <span className="sr-only"> (bắt buộc, chọn 1–20)</span>
            <span className="ml-2 font-normal text-ink-soft">Đã chọn {values.subjectIds.length}</span>
          </legend>
          {subjects.loading ? (
            <p className="text-sm text-ink-soft" role="status">
              Đang tải chuyên đề…
            </p>
          ) : subjects.error ? (
            <div className="text-sm text-danger" role="alert">
              <p>{subjects.error}</p>
              <button type="button" onClick={subjects.retry} className="focus-ring mt-1 min-h-11 rounded font-semibold text-primary underline">
                Thử lại
              </button>
            </div>
          ) : subjectOptions.length === 0 ? (
            <p className="text-sm text-ink-soft">Chưa có chuyên đề nào đang hiển thị. Hãy tạo ở mục Chuyên đề.</p>
          ) : (
            <div className="grid max-h-72 gap-x-4 overflow-y-auto sm:grid-cols-2">
              {subjectOptions.map((s) => (
                <Checkbox
                  key={s.id}
                  id={`subject-${s.id}`}
                  label={
                    <>
                      {s.name}
                      {s.note ? <span className="ml-1 text-sm text-ink-soft">({s.note})</span> : null}
                    </>
                  }
                  className="min-h-11 py-1.5 sm:min-h-10"
                  checked={values.subjectIds.includes(s.id)}
                  onChange={() => {
                    set("subjectIds", values.subjectIds.includes(s.id) ? values.subjectIds.filter((x) => x !== s.id) : [...values.subjectIds, s.id]);
                    clear("subject_ids");
                  }}
                />
              ))}
            </div>
          )}
          {errors.subject_ids ? (
            <p id="subjects-error" className="mt-1 flex items-start gap-1.5 text-sm font-medium text-danger">
              <IconAlertCircle size={16} className="mt-0.5" />
              <span>{errors.subject_ids}</span>
            </p>
          ) : null}
        </fieldset>

        <Field
          label="Mô tả ngắn"
          error={errors.short_description}
          hint={`Tối đa ${SHORT_DESC_MAX} ký tự, văn bản thuần. Hiện trên thẻ khóa học ở danh mục.`}
          aside={<span className="num text-ink-soft">{values.shortDescription.length}/{SHORT_DESC_MAX}</span>}
        >
          <Textarea
            name="short_description"
            rows={3}
            value={values.shortDescription}
            disabled={pending}
            className="text-sm"
            onChange={(e) => {
              set("shortDescription", e.target.value);
              clear("short_description");
            }}
          />
        </Field>

        <Field
          label="Mô tả chi tiết"
          required
          error={errors.description}
          hint="Gõ văn bản thường (cách một dòng trống để tách đoạn) hoặc HTML cơ bản: <p>, <h2>, <ul><li>, <strong>, <em>, <a href>. Máy chủ lọc bỏ thẻ và thuộc tính không an toàn."
        >
          <Textarea
            name="description"
            rows={8}
            value={values.description}
            disabled={pending}
            className="text-sm"
            onChange={(e) => {
              set("description", e.target.value);
              clear("description");
            }}
          />
        </Field>
      </div>

      <div className="flex flex-col gap-6">
        <ThumbnailField
          key={resetKey}
          required={mode === "create"}
          currentUrl={course?.thumbnail_url}
          cover={{ title: values.title || course?.title || "", gradeLevel: gradeNumber, subjectSlug: course?.subjects[0]?.slug }}
          file={values.thumbnail}
          error={errors.thumbnail}
          disabled={pending}
          onChange={(file) => {
            set("thumbnail", file);
            clear("thumbnail");
          }}
        />

        {mode === "create" ? (
          isStaff ? (
            <section aria-labelledby="giao-vien" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-5">
              <h2 id="giao-vien" className="text-base font-semibold text-ink">
                Giáo viên phụ trách{" "}
                <span className="text-danger" aria-hidden="true">
                  *
                </span>
                <span className="sr-only"> (bắt buộc)</span>
              </h2>
              <TeacherPicker
                options={teacherOptions}
                selected={pickedTeachers}
                onChange={(ids) => {
                  set("teacherIds", ids);
                  clear("teacher_ids");
                }}
                onLastRemove={() => setErrors((e) => ({ ...e, teacher_ids: TEACHER_REQUIRED_MESSAGE }))}
                error={errors.teacher_ids}
                loading={teachers.loading}
                loadError={teachers.error}
                onRetry={teachers.retry}
                disabled={pending}
              />
            </section>
          ) : (
            <section aria-labelledby="giao-vien" className="rounded-card border border-line bg-surface p-5">
              <h2 id="giao-vien" className="text-base font-semibold text-ink">
                Giáo viên phụ trách
              </h2>
              <p className="mt-2 text-sm text-ink">Bạn (tự động là giáo viên phụ trách)</p>
              <p className="mt-1 text-sm text-ink-soft">Khóa học sẽ được Admin xem xét và xuất bản. Admin/Quản lý trang phân công thêm giáo viên.</p>
            </section>
          )
        ) : null}

        {/* TODO(FA4): khi có màn Chương & bài, đổi lời thành "Sau khi lưu, thêm chương và bài học". */}
        {mode === "create" ? (
          <p className="rounded-card border border-line bg-surface p-5 text-sm text-ink-soft">
            Khóa mới ở trạng thái <strong className="text-ink">Nháp</strong>. Cần ít nhất 1 chương và 1 bài học mới xuất bản được; màn soạn chương/bài sẽ có ở bản tiếp theo.
          </p>
        ) : null}

        {aside}
      </div>

      {/* Thanh lưu dính đáy: luôn thấy nút Lưu khi form dài. */}
      <div className="sticky bottom-0 z-10 -mx-4 flex justify-end gap-3 border-t border-line bg-surface px-4 py-3 sm:-mx-6 sm:px-6 lg:col-span-2 lg:-mx-8 lg:px-8">
        <Button type="button" variant="secondary" size="sm" className="max-sm:h-11" disabled={pending || !dirty} onClick={reset}>
          Huỷ thay đổi
        </Button>
        <Button type="submit" size="sm" className="max-sm:h-11" loading={pending} loadingText="Đang lưu…">
          {mode === "create" ? "Tạo khóa học (nháp)" : "Lưu thay đổi"}
        </Button>
      </div>
    </form>
  );
}

function hasInput(v: CourseFormValues): boolean {
  return v.title.trim() !== "" || v.description.trim() !== "" || v.shortDescription.trim() !== "" || v.thumbnail !== null || v.subjectIds.length > 0 || v.price !== "";
}
