"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState, type FormEvent, type ReactNode } from "react";
import {
  Alert,
  Badge,
  Button,
  ConfirmDialog,
  Field,
  IconCheck,
  IconCheckCircle,
  IconChevronLeft,
  IconChevronRight,
  IconSigma,
  IconTrash,
  Textarea,
  cx,
} from "@vitaminvui/ui/v2";
import { createQuestion, deleteQuestion, updateQuestion } from "@/lib/quiz/api";
import { isGone, questionFieldErrors, quizError, type QuestionErrorKey, type QuestionErrors } from "@/lib/quiz/errors";
import {
  ODD_DOLLAR_WARNING,
  SNIPPETS,
  clockVN,
  draftFromQuestion,
  draftToPayload,
  emptyDraft,
  hasOddDollar,
  insertSnippet,
  sameDraft,
  validateDraft,
  type QuestionDraft,
} from "@/lib/quiz/logic";
import { LETTERS, QUIZ_LIMITS, type QuizQuestion } from "@/lib/quiz/types";
import { MathText } from "./MathText";

type FieldKey = "content" | "explanation" | "o0" | "o1" | "o2" | "o3";

export const PREVIEW_DEBOUNCE_MS = 250;

/** Trả `value` sau `ms` kể từ lần đổi cuối (xem trước công thức không render KaTeX theo từng phím). */
export function useDebounced<T>(value: T, ms: number): T {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms);
    return () => clearTimeout(t);
  }, [value, ms]);
  return v;
}

export type SaveNotice = { at: string; replaced: boolean };

export interface QuestionEditorProps {
  courseId: number;
  quizId: number;
  /** null = câu mới (POST). */
  question: QuizQuestion | null;
  /** Số thứ tự hiển thị (vị trí trong danh sách, bắt đầu từ 1). */
  number: number;
  prevHref?: string;
  nextHref?: string;
  listHref: string;
  /** Câu đã lưu vừa rồi (parent giữ qua lần dựng lại khi id đổi/tạo mới). */
  notice?: SaveNotice | null;
  onSaved: (saved: QuizQuestion, info: { created: boolean; replacedId: number | null }) => void;
  onDeleted: (id: number) => void;
  /** Câu/bài tập đã bị xoá nơi khác (404). */
  onGone: () => void;
  onDirtyChange: (dirty: boolean) => void;
}

/**
 * Soạn một câu trắc nghiệm (US-007 phía soạn; design-system-v2 §14.1). Trái: form; phải: xem trước đúng như học sinh thấy
 * (KaTeX cùng cấu hình, debounce). PUT câu đã có lượt làm trả `id` MỚI (copy-on-write) → báo cho giáo viên và dùng id mới.
 */
export function QuestionEditor({ courseId, quizId, question, number, prevHref, nextHref, listHref, notice, onSaved, onDeleted, onGone, onDirtyChange }: QuestionEditorProps) {
  const isNew = question === null;
  const [base, setBase] = useState<QuestionDraft>(() => (question ? draftFromQuestion(question) : emptyDraft()));
  const [draft, setDraft] = useState<QuestionDraft>(base);
  const [errors, setErrors] = useState<QuestionErrors>({});
  const [banner, setBanner] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [localNotice, setLocalNotice] = useState<SaveNotice | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [summaryTick, setSummaryTick] = useState(0);
  const savingRef = useRef(false);
  const deletingRef = useRef(false);
  const refs = useRef<Partial<Record<FieldKey, HTMLTextAreaElement | null>>>({});
  const lastFocused = useRef<FieldKey>("content");
  const summaryRef = useRef<HTMLDivElement>(null);
  const shown = localNotice ?? notice ?? null;

  const dirty = !sameDraft(draft, base);
  useEffect(() => {
    onDirtyChange(dirty);
  }, [dirty, onDirtyChange]);
  useEffect(() => () => onDirtyChange(false), [onDirtyChange]);
  useEffect(() => {
    if (summaryTick > 0) summaryRef.current?.focus();
  }, [summaryTick]);
  const clearError = useCallback((k: QuestionErrorKey) => setErrors((e) => (e[k] ? { ...e, [k]: undefined } : e)), []);

  function setField(key: FieldKey, v: string) {
    setDraft((d) => (key === "content" || key === "explanation" ? { ...d, [key]: v } : { ...d, options: d.options.map((x, i) => (`o${i}` === key ? v : x)) }));
    clearError(key);
  }

  function insert(snippet: string) {
    const key = lastFocused.current;
    const el = refs.current[key];
    if (!el) return;
    const res = insertSnippet(el.value, el.selectionStart ?? el.value.length, el.selectionEnd ?? el.value.length, snippet);
    setField(key, res.value);
    requestAnimationFrame(() => {
      el.focus();
      el.setSelectionRange(res.caret, res.caret);
    });
  }

  async function onSubmit(ev: FormEvent<HTMLFormElement>) {
    ev.preventDefault();
    if (savingRef.current) return;
    const e = validateDraft(draft);
    setErrors(e);
    setBanner(null);
    if (Object.keys(e).length > 0) {
      setSummaryTick((n) => n + 1);
      return;
    }
    savingRef.current = true;
    setSaving(true);
    setLocalNotice(null);
    try {
      const payload = draftToPayload(draft);
      const saved = question ? await updateQuestion(courseId, quizId, question.id, payload) : await createQuestion(courseId, quizId, payload);
      const replacedId = question && saved.id !== question.id ? question.id : null;
      const next = draftFromQuestion(saved);
      setBase(next);
      setDraft(next);
      setLocalNotice({ at: clockVN(new Date()), replaced: replacedId !== null });
      onSaved(saved, { created: isNew, replacedId });
    } catch (err) {
      if (isGone(err)) {
        onGone();
        return;
      }
      const { fields, unmapped } = questionFieldErrors(err);
      setErrors(fields);
      setBanner(unmapped ?? (Object.keys(fields).length > 0 ? null : quizError(err)));
      setSummaryTick((n) => n + 1);
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  async function doDelete() {
    if (!question || deletingRef.current) return;
    deletingRef.current = true;
    setDeleting(true);
    try {
      await deleteQuestion(courseId, quizId, question.id);
      onDeleted(question.id);
    } catch (err) {
      if (isGone(err)) {
        onDeleted(question.id);
        return;
      }
      setBanner(quizError(err));
      setConfirmDelete(false);
    } finally {
      deletingRef.current = false;
      setDeleting(false);
    }
  }

  const errorList = (Object.entries(errors) as Array<[QuestionErrorKey, string | undefined]>).filter((x): x is [QuestionErrorKey, string] => Boolean(x[1]));
  const fieldId = (k: string) => `q-${k}`;
  // Chỉ là lưu ý (server không từ chối): tông warning, KHÔNG dùng prop `error` (không aria-invalid, không báo như lỗi).
  const warn = (s: string, base?: ReactNode): ReactNode =>
    hasOddDollar(s) ? (
      <>
        {base ? <span className="block">{base}</span> : null}
        <span className="block font-medium text-warning" data-testid="dollar-warning">
          Lưu ý: {ODD_DOLLAR_WARNING} Nếu $ là ký hiệu tiền, hãy viết \$.
        </span>
      </>
    ) : (
      base
    );
  const labelOf = (k: QuestionErrorKey) => (k === "content" ? "Nội dung câu hỏi" : k === "explanation" ? "Lời giải" : k === "correct" ? "Đáp án đúng" : `Đáp án ${LETTERS[Number(k.slice(1))]}`);

  // Xem trước: debounce để không render KaTeX theo từng phím.
  const previewKey = JSON.stringify(draft);
  const debouncedKey = useDebounced(previewKey, PREVIEW_DEBOUNCE_MS);
  const preview: QuestionDraft = JSON.parse(debouncedKey) as QuestionDraft;

  return (
    <div className="grid gap-6 xl:grid-cols-2">
      <form noValidate onSubmit={onSubmit} aria-labelledby="soan-cau" className="flex min-w-0 flex-col gap-5" data-testid="question-form">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 id="soan-cau" className="text-heading font-extrabold tracking-heading text-ink">
            {isNew ? `Câu mới (sẽ là câu ${number})` : `Câu ${number}`}
          </h2>
          <div className="flex gap-1">
            {prevHref ? (
              <Link href={prevHref} scroll={false} className="focus-ring inline-flex h-9 items-center gap-1 rounded-control px-2 text-sm font-semibold text-primary hover:bg-primary-soft max-sm:h-11">
                <IconChevronLeft size={16} /> Câu trước
              </Link>
            ) : null}
            {nextHref ? (
              <Link href={nextHref} scroll={false} className="focus-ring inline-flex h-9 items-center gap-1 rounded-control px-2 text-sm font-semibold text-primary hover:bg-primary-soft max-sm:h-11">
                Câu sau <IconChevronRight size={16} />
              </Link>
            ) : null}
          </div>
        </div>

        {errorList.length > 0 || banner ? (
          <div ref={summaryRef} id="tom-tat-loi" tabIndex={-1} className="focus-ring rounded-card">
            <Alert tone="danger" title={errorList.length > 0 ? `Chưa lưu được — còn ${errorList.length} chỗ cần sửa` : "Chưa lưu được"}>
              {banner ? <p>{banner}</p> : null}
              {errorList.length > 0 ? (
                <ul className="list-disc pl-5">
                  {errorList.map(([k, msg]) => (
                    <li key={k}>
                      <a
                        href={`#${fieldId(k === "correct" ? "o0-dung" : k)}`}
                        onClick={(e) => {
                          e.preventDefault();
                          const el = document.getElementById(fieldId(k === "correct" ? "o0-dung" : k));
                          el?.scrollIntoView({ block: "center" });
                          el?.focus();
                        }}
                        className="font-semibold text-danger underline underline-offset-2"
                      >
                        {labelOf(k)}
                      </a>
                      : {msg}
                    </li>
                  ))}
                </ul>
              ) : null}
            </Alert>
          </div>
        ) : null}
        {shown?.replaced ? (
          <Alert tone="info" title="Đã lưu thành bản mới của câu hỏi">
            Câu này đã có học sinh làm, nên hệ thống giữ nguyên câu cũ cho các lượt đã làm và lưu nội dung mới thành câu mới ở cùng vị trí. Học sinh làm từ bây giờ sẽ thấy nội dung mới.
          </Alert>
        ) : null}

        <fieldset disabled={saving} className="flex flex-col gap-5">
          <div className="flex flex-col gap-2 rounded-card border border-line bg-sunken p-3">
            <div role="toolbar" aria-label="Chèn công thức vào ô đang soạn" className="flex flex-wrap gap-1.5">
              <span className="mr-1 inline-flex items-center gap-1 text-sm font-semibold text-ink">
                <IconSigma size={16} /> Chèn:
              </span>
              {SNIPPETS.map((s) => (
                <button
                  key={s.label}
                  type="button"
                  onMouseDown={(e) => e.preventDefault()}
                  onClick={() => insert(s.text)}
                  className="focus-ring inline-flex h-9 min-w-9 items-center justify-center rounded-control border border-line-strong bg-surface px-2 font-mono text-sm text-ink hover:border-primary hover:text-primary max-sm:h-11 max-sm:min-w-11"
                >
                  <span aria-hidden="true">{s.label}</span>
                  <span className="sr-only">{s.sr}</span>
                </button>
              ))}
            </div>
            <p className="text-sm text-ink-soft">
              Viết công thức giữa hai dấu <code className="font-mono text-ink">$…$</code> (trong dòng) hoặc <code className="font-mono text-ink">$$…$$</code> (riêng dòng). Dấu nhỏ hơn/lớn hơn viết sát chữ phải dùng{" "}
              <code className="font-mono text-ink">\lt</code>, <code className="font-mono text-ink">\gt</code> (ví dụ <code className="font-mono text-ink">$a \lt b$</code>) — hệ thống không nhận văn bản giống thẻ HTML.
            </p>
          </div>

          <Field
            id={fieldId("content")}
            label="Nội dung câu hỏi"
            required
            error={errors.content}
            hint={warn(draft.content)}
            aside={
              <span className="num text-ink-soft">
                {draft.content.length.toLocaleString("vi-VN")}/{QUIZ_LIMITS.content.toLocaleString("vi-VN")}
              </span>
            }
          >
            <Textarea
              ref={(el) => {
                refs.current.content = el;
              }}
              rows={5}
              maxLength={QUIZ_LIMITS.content}
              value={draft.content}
              onFocus={() => {
                lastFocused.current = "content";
              }}
              onChange={(e) => setField("content", e.target.value)}
              placeholder="Ví dụ: Cho $\widehat{BAC} = 35^\circ$. Số đo cung $BC$ là:"
            />
          </Field>

          <fieldset className="flex flex-col gap-3">
            <legend className="mb-1 text-sm font-semibold text-ink">
              Đáp án <span className="font-normal text-ink-soft">— chọn đúng 1 đáp án đúng</span>
            </legend>
            {errors.correct ? <p className="text-sm font-medium text-danger">{errors.correct}</p> : null}
            {draft.options.map((o, i) => {
              const k = `o${i}` as FieldKey;
              const isCorrect = draft.correct === i;
              return (
                <div key={k} className={cx("flex flex-col gap-2 rounded-card border p-3", isCorrect ? "border-success bg-success-soft" : "border-line bg-surface")}>
                  <div className="flex items-center justify-between gap-2">
                    <span className="flex items-center gap-2 text-sm font-semibold text-ink">
                      <span className={cx("flex size-7 items-center justify-center rounded-full border-2 font-extrabold", isCorrect ? "border-success bg-success text-on-status" : "border-line-strong text-ink")}>{LETTERS[i]}</span>
                      Đáp án {LETTERS[i]}
                    </span>
                    <label className="flex min-h-9 cursor-pointer items-center gap-2 rounded-control px-2 text-sm font-semibold text-ink has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-offset-2 has-[input:focus-visible]:outline-focus max-sm:min-h-11">
                      <input
                        id={i === 0 ? fieldId("o0-dung") : undefined}
                        type="radio"
                        name="correct"
                        checked={isCorrect}
                        onChange={() => {
                          setDraft((d) => ({ ...d, correct: i }));
                          clearError("correct");
                        }}
                        className="size-4 accent-success"
                      />
                      {isCorrect ? (
                        <span className="inline-flex items-center gap-1 text-success">
                          <IconCheck size={16} strokeWidth={2.5} /> Đáp án đúng
                        </span>
                      ) : (
                        "Là đáp án đúng"
                      )}
                      <span className="sr-only"> (đáp án {LETTERS[i]})</span>
                    </label>
                  </div>
                  <Field
                    id={fieldId(k)}
                    label={<span className="sr-only">Nội dung đáp án {LETTERS[i]}</span>}
                    error={errors[k]}
                    hint={warn(o)}
                    aside={o.length > 800 ? <span className="num text-ink-soft">{o.length}/{QUIZ_LIMITS.option}</span> : undefined}
                  >
                    <Textarea
                      ref={(el) => {
                        refs.current[k] = el;
                      }}
                      rows={1}
                      maxLength={QUIZ_LIMITS.option}
                      value={o}
                      onFocus={() => {
                        lastFocused.current = k;
                      }}
                      onChange={(e) => setField(k, e.target.value)}
                      className="min-h-11 resize-y"
                    />
                  </Field>
                </div>
              );
            })}
          </fieldset>

          <Field
            id={fieldId("explanation")}
            label="Lời giải"
            hint={warn(draft.explanation, "Không bắt buộc. Học sinh thấy sau khi nộp bài.")}
            error={errors.explanation}
            aside={
              <span className="num text-ink-soft">
                {draft.explanation.length.toLocaleString("vi-VN")}/{QUIZ_LIMITS.explanation.toLocaleString("vi-VN")}
              </span>
            }
          >
            <Textarea
              ref={(el) => {
                refs.current.explanation = el;
              }}
              rows={4}
              maxLength={QUIZ_LIMITS.explanation}
              value={draft.explanation}
              onFocus={() => {
                lastFocused.current = "explanation";
              }}
              onChange={(e) => setField("explanation", e.target.value)}
            />
          </Field>
        </fieldset>

        <div className="sticky bottom-0 -mx-4 flex flex-wrap items-center gap-3 border-t border-line bg-surface px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 xl:static xl:mx-0 xl:border-0 xl:bg-transparent xl:px-0">
          <Button type="submit" size="sm" className="max-sm:h-11" loading={saving} loadingText="Đang lưu…">
            {isNew ? "Thêm câu hỏi" : "Lưu câu hỏi"}
          </Button>
          <Link href={listHref} className="focus-ring inline-flex h-9 items-center rounded-control px-3 text-sm font-semibold text-ink hover:bg-sunken max-sm:h-11">
            Về danh sách câu
          </Link>
          <span aria-live="polite" className="text-sm text-ink-soft">
            {shown ? (
              <span className="inline-flex items-center gap-1 text-success" data-testid="saved-at">
                <IconCheckCircle size={16} className="motion-safe:animate-tick" /> Đã lưu lúc {shown.at}
              </span>
            ) : null}
          </span>
          {!isNew ? (
            <Button type="button" variant="ghost" size="sm" className="ml-auto text-danger hover:bg-danger-soft max-sm:h-11" leadingIcon={<IconTrash size={16} />} onClick={() => setConfirmDelete(true)}>
              Xoá câu
            </Button>
          ) : null}
        </div>
      </form>

      <aside aria-labelledby="xem-truoc" className="min-w-0 xl:sticky xl:top-6 xl:self-start" data-testid="question-preview">
        <div className="flex items-center justify-between gap-2">
          <h2 id="xem-truoc" className="text-base font-semibold text-ink">
            Xem trước — học sinh sẽ thấy
          </h2>
          <Badge size="sm">Cập nhật khi gõ</Badge>
        </div>
        <div className="mt-3 flex flex-col gap-4 rounded-card border border-line bg-surface p-4 sm:p-5">
          <p className="text-sm font-semibold text-ink-soft">Câu {number}</p>
          {preview.content.trim() ? <MathText content={preview.content} className="text-question text-ink" /> : <p className="text-base italic text-ink-soft">Nội dung câu hỏi sẽ hiện ở đây.</p>}
          <ul className="flex flex-col gap-2">
            {preview.options.map((o, i) => (
              <li key={i} className={cx("flex min-h-14 items-center gap-3 rounded-control border px-3 py-2", preview.correct === i ? "border-success bg-success-soft" : "border-line-strong")}>
                <span className={cx("flex size-8 shrink-0 items-center justify-center rounded-full border-2 font-extrabold", preview.correct === i ? "border-success bg-success text-on-status" : "border-line-strong text-ink")}>
                  {LETTERS[i]}
                </span>
                <span className="min-w-0 flex-1 text-base text-ink">{o.trim() ? <MathText as="span" content={o} /> : <span className="italic text-ink-soft">Chưa có nội dung</span>}</span>
                {preview.correct === i ? <span className="shrink-0 text-sm font-semibold text-success">Đáp án đúng</span> : null}
              </li>
            ))}
          </ul>
          {preview.explanation.trim() ? (
            <div className="rounded-card bg-sunken p-4">
              <p className="text-sm font-semibold text-ink">Lời giải</p>
              <MathText content={preview.explanation} className="mt-1 text-base leading-relaxed text-ink" />
            </div>
          ) : null}
        </div>
        <p className="mt-2 text-sm text-ink-soft">Xem trước dùng KaTeX cùng cấu hình với trang học sinh. Công thức viết sai cú pháp sẽ hiện nguyên văn màu đỏ.</p>
      </aside>

      <ConfirmDialog
        open={confirmDelete}
        onClose={() => (deleting ? undefined : setConfirmDelete(false))}
        onConfirm={() => void doDelete()}
        tone="danger"
        title={`Xoá câu ${number}?`}
        description="Câu hỏi sẽ bị xoá khỏi bài tập này. Các lượt làm đã có vẫn giữ nguyên kết quả. Hành động này không thể hoàn tác."
        confirmLabel="Xoá câu"
        loading={deleting}
        loadingText="Đang xoá…"
      />
    </div>
  );
}
