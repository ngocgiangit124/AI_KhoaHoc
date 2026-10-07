"use client";

import { useMemo, useRef, useState } from "react";
import { Alert, Button, useToast } from "@vitaminvui/ui/v2";
import { setCourseTeachers } from "@/lib/courses/api";
import { isNotFound, teacherAssignError } from "@/lib/courses/errors";
import { TEACHER_REQUIRED_MESSAGE } from "@/lib/courses/form";
import type { CourseDetail } from "@/lib/courses/types";
import { TeacherPicker, type TeacherOption } from "./TeacherPicker";
import { useTeacherOptions } from "./useCourseOptions";

export interface TeachersCardProps {
  course: CourseDetail;
  onSaved: (course: CourseDetail) => void;
  /** Khóa học đã bị xoá từ nơi khác (404). */
  onGone: () => void;
}

/**
 * Gán giáo viên phụ trách (chỉ staff, `PUT /admin/courses/{id}/teachers`). Giáo viên đã gán mà sau đó bị khoá vẫn
 * giữ được khi gửi lại danh sách; chỉ người MỚI thêm phải đang hoạt động — lỗi ở `errors.teacher_ids`.
 */
export function TeachersCard({ course, onSaved, onGone }: TeachersCardProps) {
  const toast = useToast();
  const teachers = useTeacherOptions(true);
  const [selectedIds, setSelectedIds] = useState<number[]>(() => course.teachers.map((t) => t.id));
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const pendingRef = useRef(false);

  const options: TeacherOption[] = useMemo(() => teachers.items.map((t) => ({ id: t.id, name: t.name })), [teachers.items]);
  const selected: TeacherOption[] = useMemo(() => {
    const byId = new Map<number, TeacherOption>(options.map((t) => [t.id, t]));
    const known = new Map(course.teachers.map((t) => [t.id, t]));
    return selectedIds.map((id) => {
      const active = byId.get(id);
      if (active) return active;
      const t = known.get(id);
      return { id, name: t?.name ?? `Giáo viên #${id}`, note: teachers.loading ? undefined : "không còn hoạt động" };
    });
  }, [selectedIds, options, course.teachers, teachers.loading]);

  const initial = course.teachers.map((t) => t.id);
  const unchanged = selectedIds.length === initial.length && selectedIds.every((id) => initial.includes(id));

  async function save() {
    if (pendingRef.current) return;
    if (selectedIds.length === 0) {
      setError(TEACHER_REQUIRED_MESSAGE);
      return;
    }
    pendingRef.current = true;
    setPending(true);
    setError(null);
    try {
      const saved = await setCourseTeachers(course.id, selectedIds);
      toast.show({ tone: "success", title: "Đã cập nhật giáo viên phụ trách" });
      onSaved(saved);
    } catch (err) {
      setError(teacherAssignError(err));
      if (isNotFound(err)) onGone();
    } finally {
      pendingRef.current = false;
      setPending(false);
    }
  }

  return (
    <section aria-labelledby="giao-vien" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-5">
      <h2 id="giao-vien" className="text-base font-semibold text-ink">
        Giáo viên phụ trách <span className="text-danger" aria-hidden="true">*</span>
      </h2>
      <TeacherPicker
        options={options}
        selected={selected}
        onChange={(ids) => {
          setSelectedIds(ids);
          setError(null);
        }}
        error={error ?? undefined}
        loading={teachers.loading}
        loadError={teachers.error}
        onRetry={teachers.retry}
        disabled={pending}
        onLastRemove={() => setError(TEACHER_REQUIRED_MESSAGE)}
      />
      {unchanged ? null : <Alert tone="info">Có thay đổi chưa lưu.</Alert>}
      <Button type="button" size="sm" variant="secondary" className="self-start max-sm:h-11" onClick={() => void save()} loading={pending} disabled={unchanged}>
        Lưu giáo viên
      </Button>
    </section>
  );
}
