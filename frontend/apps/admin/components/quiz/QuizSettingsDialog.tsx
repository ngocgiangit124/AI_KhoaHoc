"use client";

import { useEffect, useId, useRef, useState, type FormEvent } from "react";
import { Alert, Button, Checkbox, Dialog, Field, Select, Skeleton, TextInput } from "@vitaminvui/ui/v2";
import { getCurriculum } from "@/lib/curriculum/api";
import type { Chapter } from "@/lib/curriculum/types";
import { createQuiz, updateQuiz } from "@/lib/quiz/api";
import { quizError, quizFieldErrors, type QuizFormErrors } from "@/lib/quiz/errors";
import { parentFromValue } from "@/lib/quiz/logic";
import { QUIZ_LIMITS, type QuizItem, type QuizPayload } from "@/lib/quiz/types";

export interface QuizSettingsDialogProps {
  courseId: number;
  /** Có `quiz` = sửa (PUT thay toàn bộ), không có = tạo (POST). */
  quiz?: QuizItem;
  onClose: () => void;
  /** `timeIgnored`: đã gửi giới hạn thời gian nhưng server không áp dụng (tính năng đang tắt). */
  onSaved: (quiz: QuizItem, info: { timeIgnored: boolean }) => void;
}

/**
 * Tạo/sửa thông tin bài tập: tên, gắn với chương HOẶC bài (đúng 1 — gộp một ô chọn có nhóm), thời gian 1–300 phút hoặc không giới hạn.
 * Dựng mới mỗi lần mở. Cây chương/bài lấy từ `GET chapters`; 422 `QUIZ_PARENT_INVALID` (chương/bài vừa bị xoá) → lỗi dưới ô "Gắn với".
 */
export function QuizSettingsDialog({ courseId, quiz, onClose, onSaved }: QuizSettingsDialogProps) {
  const formId = useId();
  const [chapters, setChapters] = useState<Chapter[] | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [reload, setReload] = useState(0);
  const [title, setTitle] = useState(quiz?.title ?? "");
  const [parent, setParent] = useState(quiz ? (quiz.lesson_id ? `lesson:${quiz.lesson_id}` : `chapter:${quiz.chapter_id}`) : "");
  const [unlimited, setUnlimited] = useState(quiz ? quiz.time_limit_minutes === null : false);
  const [minutes, setMinutes] = useState(String(quiz?.time_limit_minutes ?? 15));
  const [errors, setErrors] = useState<QuizFormErrors>({});
  const [banner, setBanner] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const busyRef = useRef(false);

  useEffect(() => {
    const c = new AbortController();
    getCurriculum(courseId, c.signal)
      .then((res) => {
        setChapters(res.chapters);
        setLoadError(null);
      })
      .catch((err: unknown) => {
        if (!c.signal.aborted) setLoadError(quizError(err));
      });
    return () => c.abort();
  }, [courseId, reload]);

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (busyRef.current) return;
    const next: QuizFormErrors = {};
    const name = title.trim();
    const parentPart = parentFromValue(parent);
    const n = Number(minutes);
    if (!name) next.title = "Vui lòng nhập tên bài tập.";
    else if (name.length > QUIZ_LIMITS.title) next.title = `Tên tối đa ${QUIZ_LIMITS.title} ký tự.`;
    if (!parentPart) next.parent = "Chọn chương hoặc bài học mà bài tập này đi kèm.";
    if (!unlimited && (!/^\d+$/.test(minutes.trim()) || n < QUIZ_LIMITS.timeMin || n > QUIZ_LIMITS.timeMax)) next.time = `Thời gian từ ${QUIZ_LIMITS.timeMin} đến ${QUIZ_LIMITS.timeMax} phút.`;
    setErrors(next);
    setBanner(null);
    if (Object.keys(next).length > 0 || !parentPart) return;
    busyRef.current = true;
    setBusy(true);
    const payload: QuizPayload = { title: name, ...parentPart, time_limit_minutes: unlimited ? null : n };
    try {
      const saved = quiz ? await updateQuiz(courseId, quiz.id, payload) : await createQuiz(courseId, payload);
      const timeIgnored = quiz ? saved.time_limit_minutes !== payload.time_limit_minutes : payload.time_limit_minutes !== null && saved.time_limit_minutes === null;
      onSaved(saved, { timeIgnored });
    } catch (err) {
      const { fields, unmapped } = quizFieldErrors(err);
      setErrors(fields);
      setBanner(unmapped ?? (Object.keys(fields).length > 0 ? null : quizError(err)));
      busyRef.current = false;
      setBusy(false);
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      dismissible={!busy}
      title={quiz ? "Thông tin bài tập" : "Tạo bài tập"}
      description="Học sinh thấy bài tập ngay dưới chương hoặc bài học được chọn."
      footer={
        <>
          <Button variant="secondary" size="sm" className="max-sm:h-11" onClick={onClose} disabled={busy}>
            Huỷ
          </Button>
          <Button type="submit" form={formId} size="sm" className="max-sm:h-11" loading={busy} loadingText="Đang lưu…" disabled={chapters === null}>
            {quiz ? "Lưu" : "Tạo bài tập"}
          </Button>
        </>
      }
    >
      <form id={formId} noValidate onSubmit={submit} className="flex flex-col gap-4">
        {banner ? <Alert tone="danger">{banner}</Alert> : null}
        <Field label="Tên bài tập" required error={errors.title}>
          <TextInput autoFocus size="sm" className="max-sm:h-11" maxLength={QUIZ_LIMITS.title} value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Ví dụ: Luyện tập tổng hợp chương 2" />
        </Field>
        {loadError ? (
          <Alert
            tone="danger"
            title="Không tải được danh sách chương và bài"
            action={
              <Button size="sm" variant="secondary" className="max-sm:h-11" onClick={() => setReload((x) => x + 1)}>
                Thử lại
              </Button>
            }
          >
            {loadError}
          </Alert>
        ) : chapters === null ? (
          <Skeleton className="h-16 w-full" />
        ) : (
          <Field label="Gắn với" required error={errors.parent} hint="Chọn cả chương (bài tập cuối chương) hoặc một bài học cụ thể.">
            <Select size="sm" className="max-sm:h-11" value={parent} onChange={(e) => setParent(e.target.value)}>
              <option value="">Chọn chương hoặc bài…</option>
              {chapters.map((c) => (
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
        )}
        <div className="flex flex-col gap-2">
          <Field
            label="Thời gian làm bài (phút)"
            required={!unlimited}
            error={errors.time}
            hint={unlimited ? "Học sinh làm không giới hạn thời gian." : "Từ 1 đến 300 phút. Hết giờ bài tự nộp. Nếu giới hạn thời gian đang tắt trên hệ thống, giá trị này chưa được áp dụng."}
          >
            <TextInput type="number" inputMode="numeric" size="sm" min={1} max={300} disabled={unlimited} value={minutes} onChange={(e) => setMinutes(e.target.value)} className="max-w-32 max-sm:h-11" />
          </Field>
          <Checkbox id={`${formId}-unlimited`} label="Không giới hạn thời gian" checked={unlimited} onChange={(e) => setUnlimited(e.target.checked)} />
        </div>
      </form>
    </Dialog>
  );
}
