"use client";

import { useState, type FormEvent } from "react";
import { Button, Checkbox, Dialog, Field, IconPencil, IconPlus, Select, TextInput, useToast } from "@vitaminvui/ui/v2";
import { QUIZ_LIMITS, type QuizResource } from "@/lib/mock/v2/quizzes";

export interface ParentOption {
  id: number;
  title: string;
  lessons: Array<{ id: number; title: string }>;
}

/**
 * Tạo/sửa thông tin bài tập (POST/PUT /admin/courses/{c}/quizzes): tên, gắn với chương HOẶC bài
 * (đúng 1 — gộp thành một ô chọn có nhóm), thời gian làm bài (null = không giới hạn; 1–300 phút).
 * Khi `quiz_time_limit_enabled=false` ô thời gian bị ẩn kèm câu giải thích (server bỏ qua trường này).
 * TODO(dev): nối API; 422 QUIZ_PARENT_INVALID (chương/bài vừa bị xoá) → lỗi dưới ô "Gắn với" + tải lại cây.
 */
export function QuizSettingsDialog({
  mode,
  quiz,
  parents,
  timeLimitEnabled = true,
}: {
  mode: "create" | "edit";
  quiz?: QuizResource;
  parents: ParentOption[];
  timeLimitEnabled?: boolean;
}) {
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [unlimited, setUnlimited] = useState(quiz ? quiz.time_limit_minutes === null : false);
  const [errors, setErrors] = useState<{ title?: string; parent?: string; time?: string }>({});
  const defaultParent = quiz ? (quiz.lesson_id ? `lesson:${quiz.lesson_id}` : `chapter:${quiz.chapter_id}`) : "";

  function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    const title = String(f.get("title") ?? "").trim();
    const parent = String(f.get("parent") ?? "");
    const time = Number(f.get("time_limit_minutes"));
    const next: typeof errors = {};
    if (!title) next.title = "Vui lòng nhập tên bài tập.";
    if (!parent) next.parent = "Chọn chương hoặc bài học mà bài tập này đi kèm.";
    if (timeLimitEnabled && !unlimited && (!Number.isInteger(time) || time < QUIZ_LIMITS.timeMin || time > QUIZ_LIMITS.timeMax))
      next.time = `Thời gian từ ${QUIZ_LIMITS.timeMin} đến ${QUIZ_LIMITS.timeMax} phút.`;
    setErrors(next);
    if (Object.keys(next).length) return;
    setSaving(true);
    setTimeout(() => {
      setSaving(false);
      setOpen(false);
      toast.show({ tone: "success", title: mode === "create" ? "Đã tạo bài tập" : "Đã lưu thông tin bài tập" });
    }, 800);
  }

  return (
    <>
      {mode === "create" ? (
        <Button size="sm" leadingIcon={<IconPlus size={16} />} onClick={() => setOpen(true)}>
          Tạo bài tập
        </Button>
      ) : (
        <Button size="sm" variant="secondary" leadingIcon={<IconPencil size={16} />} onClick={() => setOpen(true)}>
          Sửa thông tin
        </Button>
      )}
      <Dialog
        open={open}
        onClose={() => setOpen(false)}
        dismissible={!saving}
        title={mode === "create" ? "Tạo bài tập" : "Thông tin bài tập"}
        description="Học sinh thấy bài tập ngay dưới chương hoặc bài học được chọn."
        footer={
          <>
            <Button variant="secondary" size="sm" onClick={() => setOpen(false)} disabled={saving}>
              Huỷ
            </Button>
            <Button type="submit" form="quiz-settings" size="sm" loading={saving} loadingText="Đang lưu…">
              {mode === "create" ? "Tạo bài tập" : "Lưu"}
            </Button>
          </>
        }
      >
        <form id="quiz-settings" noValidate onSubmit={onSubmit} className="flex flex-col gap-4">
          <Field label="Tên bài tập" required error={errors.title}>
            <TextInput name="title" size="sm" maxLength={QUIZ_LIMITS.title} defaultValue={quiz?.title} placeholder="Ví dụ: Luyện tập tổng hợp chương 2" />
          </Field>
          <Field label="Gắn với" required error={errors.parent} hint="Chọn cả chương (bài tập cuối chương) hoặc một bài học cụ thể.">
            <Select name="parent" size="sm" defaultValue={defaultParent}>
              <option value="">Chọn chương hoặc bài…</option>
              {parents.map((c) => (
                <optgroup key={c.id} label={c.title}>
                  <option value={`chapter:${c.id}`}>Cả chương — {c.title}</option>
                  {c.lessons.map((l) => (
                    <option key={l.id} value={`lesson:${l.id}`}>
                      {l.title}
                    </option>
                  ))}
                </optgroup>
              ))}
            </Select>
          </Field>
          {timeLimitEnabled ? (
            <div className="flex flex-col gap-2">
              <Field label="Thời gian làm bài (phút)" required={!unlimited} error={errors.time} hint={unlimited ? "Học sinh làm không giới hạn thời gian." : "Từ 1 đến 300 phút. Hết giờ bài tự nộp."}>
                <TextInput name="time_limit_minutes" type="number" inputMode="numeric" size="sm" min={1} max={300} disabled={unlimited} defaultValue={quiz?.time_limit_minutes ?? 15} className="max-w-32" />
              </Field>
              <Checkbox id="quiz-unlimited" label="Không giới hạn thời gian" checked={unlimited} onChange={(e) => setUnlimited(e.target.checked)} />
            </div>
          ) : (
            <p className="text-sm text-ink-soft">Giới hạn thời gian làm bài đang tắt cho toàn hệ thống, nên học sinh làm không giới hạn.</p>
          )}
        </form>
      </Dialog>
    </>
  );
}
