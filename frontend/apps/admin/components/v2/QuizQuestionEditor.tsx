"use client";

import Link from "next/link";
import { useRef, useState, type FormEvent } from "react";
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
  MathText,
  Textarea,
  cx,
} from "@vitaminvui/ui/v2";
import { QUIZ_LIMITS, type QuizQuestionResource } from "@/lib/mock/v2/quizzes";

const LETTERS = ["A", "B", "C", "D"] as const;
type FieldKey = "content" | "explanation" | "o0" | "o1" | "o2" | "o3";

/** Đoạn chèn nhanh: chèn vào ô đang soạn, đặt con trỏ vào chỗ cần gõ tiếp (`|`). */
const SNIPPETS: Array<{ label: string; sr: string; text: string }> = [
  { label: "$x$", sr: "Công thức trong dòng", text: "$|$" },
  { label: "$$x$$", sr: "Công thức riêng dòng", text: "\n$$|$$\n" },
  { label: "a/b", sr: "Phân số", text: "\\dfrac{|}{}" },
  { label: "√", sr: "Căn bậc hai", text: "\\sqrt{|}" },
  { label: "x²", sr: "Số mũ", text: "^{|}" },
  { label: "°", sr: "Độ", text: "^\\circ|" },
  { label: "π", sr: "Pi", text: "\\pi|" },
  { label: "≠", sr: "Khác", text: "\\ne |" },
  { label: "≤", sr: "Nhỏ hơn hoặc bằng", text: "\\le |" },
  { label: "≥", sr: "Lớn hơn hoặc bằng", text: "\\ge |" },
  { label: "\\lt", sr: "Dấu nhỏ hơn", text: "\\lt |" },
  { label: "\\gt", sr: "Dấu lớn hơn", text: "\\gt |" },
];

/** Giống luật server (T21): `<` liền chữ hoặc `/ ! ?` bị coi là thẻ HTML → 422. */
const HTML_LIKE = /<[A-Za-z/!?]/;
const HTML_MSG = "Có đoạn giống thẻ HTML (dấu < viết sát chữ). Trong công thức hãy dùng \\lt và \\gt, ví dụ $a \\lt b$.";

function oddDollar(s: string): boolean {
  const n = (s.replace(/\\\$/g, "").match(/\$/g) ?? []).length;
  return n % 2 === 1;
}

export type EditorDemo = "loi-luu" | "dang-luu" | "ban-moi";

export interface QuizQuestionEditorProps {
  /** null = câu mới (POST). */
  question: Pick<QuizQuestionResource, "content" | "explanation"> & { id?: number; options: Array<{ content: string; is_correct: boolean }> } | null;
  position: number;
  /** Câu mới (POST) — kể cả khi có bản nháp điền sẵn. Mặc định: `question === null`. */
  isNew?: boolean;
  prevHref?: string;
  nextHref?: string;
  listHref: string;
  demo?: EditorDemo;
}

/**
 * Soạn một câu trắc nghiệm (FA5, POST/PUT /admin/courses/{c}/quizzes/{q}/questions[/{id}]).
 * Trái: form (nội dung, 4 đáp án + chọn 1 đáp án đúng, lời giải). Phải: xem trước đúng như học sinh thấy,
 * cập nhật theo từng phím. Lỗi server (422 `errors.content|options|options.N.content`) hiện dưới từng ô +
 * hộp tóm tắt đầu form. PUT câu đã có lượt làm trả `id` MỚI (copy-on-write) → dùng id mới, báo cho giáo viên.
 * TODO(dev): nối API, KaTeX cho xem trước (cùng cấu hình trang học sinh), xác nhận rời trang khi chưa lưu.
 */
export function QuizQuestionEditor({ question, position, isNew = question === null, prevHref, nextHref, listHref, demo }: QuizQuestionEditorProps) {
  const [content, setContent] = useState(question?.content ?? "");
  const [explanation, setExplanation] = useState(question?.explanation ?? "");
  const [options, setOptions] = useState<string[]>(() => (question ? question.options.map((o) => o.content) : ["", "", "", ""]));
  const [correct, setCorrect] = useState<number | null>(() => {
    if (!question) return null;
    const ok = question.options.map((o, i) => (o.is_correct ? i : -1)).filter((i) => i >= 0);
    return ok.length === 1 ? (ok[0] ?? null) : null;
  });
  const [saving, setSaving] = useState(demo === "dang-luu");
  const [savedAt, setSavedAt] = useState<string>();
  const [newVersion, setNewVersion] = useState(false);
  const [errors, setErrors] = useState<Partial<Record<FieldKey | "correct", string>>>(() =>
    demo === "loi-luu"
      ? { content: HTML_MSG, correct: "Mỗi câu phải có đúng 1 đáp án đúng.", o3: "Vui lòng nhập nội dung đáp án D." }
      : {},
  );
  const [confirmDelete, setConfirmDelete] = useState(false);
  const refs = useRef<Partial<Record<FieldKey, HTMLTextAreaElement | null>>>({});
  const lastFocused = useRef<FieldKey>("content");

  const setters: Record<FieldKey, (v: string) => void> = {
    content: setContent,
    explanation: setExplanation,
    o0: (v) => setOptions((o) => o.map((x, i) => (i === 0 ? v : x))),
    o1: (v) => setOptions((o) => o.map((x, i) => (i === 1 ? v : x))),
    o2: (v) => setOptions((o) => o.map((x, i) => (i === 2 ? v : x))),
    o3: (v) => setOptions((o) => o.map((x, i) => (i === 3 ? v : x))),
  };

  function insert(snippet: string) {
    const key = lastFocused.current;
    const el = refs.current[key];
    if (!el) return;
    const caret = snippet.indexOf("|");
    const text = snippet.replace("|", "");
    const start = el.selectionStart ?? el.value.length;
    const end = el.selectionEnd ?? start;
    const next = el.value.slice(0, start) + text + el.value.slice(end);
    setters[key](next);
    requestAnimationFrame(() => {
      el.focus();
      const pos = start + (caret >= 0 ? caret : text.length);
      el.setSelectionRange(pos, pos);
    });
  }

  function validate(): Partial<Record<FieldKey | "correct", string>> {
    const e: Partial<Record<FieldKey | "correct", string>> = {};
    if (!content.trim()) e.content = "Vui lòng nhập nội dung câu hỏi.";
    else if (HTML_LIKE.test(content)) e.content = HTML_MSG;
    options.forEach((o, i) => {
      const k = `o${i}` as FieldKey;
      if (!o.trim()) e[k] = `Vui lòng nhập nội dung đáp án ${LETTERS[i]}.`;
      else if (HTML_LIKE.test(o)) e[k] = HTML_MSG;
    });
    if (explanation && HTML_LIKE.test(explanation)) e.explanation = HTML_MSG;
    if (correct === null) e.correct = "Chọn một đáp án đúng.";
    return e;
  }

  function onSubmit(ev: FormEvent<HTMLFormElement>) {
    ev.preventDefault();
    const e = validate();
    setErrors(e);
    if (Object.keys(e).length) {
      document.getElementById("tom-tat-loi")?.focus();
      return;
    }
    setSaving(true);
    setSavedAt(undefined);
    setTimeout(() => {
      setSaving(false);
      setSavedAt("20:15");
      setNewVersion(demo === "ban-moi");
    }, 900);
  }

  const errorList = Object.entries(errors).filter((x): x is [string, string] => Boolean(x[1]));
  const fieldId = (k: string) => `q-${k}`;
  const warn = (s: string) => (oddDollar(s) ? "Thiếu một dấu $ để đóng công thức." : undefined);

  const toolbar = (
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
            className="focus-ring inline-flex h-9 min-w-9 items-center justify-center rounded-control border border-line-strong bg-surface px-2 font-mono text-sm text-ink hover:border-primary hover:text-primary"
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
  );

  return (
    <div className="grid gap-6 xl:grid-cols-2">
      <form noValidate onSubmit={onSubmit} aria-labelledby="soan-cau" className="flex min-w-0 flex-col gap-5">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 id="soan-cau" className="text-heading font-extrabold tracking-heading text-ink">
            {isNew ? `Câu mới (sẽ là câu ${position})` : `Câu ${position}`}
          </h2>
          <div className="flex gap-1">
            {prevHref ? (
              <Link href={prevHref} scroll={false} className="focus-ring inline-flex h-9 items-center gap-1 rounded-control px-2 text-sm font-semibold text-primary hover:bg-primary-soft">
                <IconChevronLeft size={16} /> Câu trước
              </Link>
            ) : null}
            {nextHref ? (
              <Link href={nextHref} scroll={false} className="focus-ring inline-flex h-9 items-center gap-1 rounded-control px-2 text-sm font-semibold text-primary hover:bg-primary-soft">
                Câu sau <IconChevronRight size={16} />
              </Link>
            ) : null}
          </div>
        </div>

        {errorList.length ? (
          <div id="tom-tat-loi" tabIndex={-1} className="focus-ring rounded-card">
            <Alert tone="danger" title={`Chưa lưu được — còn ${errorList.length} chỗ cần sửa`}>
              <ul className="list-disc pl-5">
                {errorList.map(([k, msg]) => (
                  <li key={k}>
                    <a href={`#${fieldId(k === "correct" ? "o0-dung" : k)}`} className="font-semibold text-danger underline underline-offset-2">
                      {k === "content" ? "Nội dung câu hỏi" : k === "explanation" ? "Lời giải" : k === "correct" ? "Đáp án đúng" : `Đáp án ${LETTERS[Number(k.slice(1))]}`}
                    </a>
                    : {msg}
                  </li>
                ))}
              </ul>
            </Alert>
          </div>
        ) : null}
        {newVersion ? (
          <Alert tone="info" title="Đã lưu thành bản mới của câu hỏi">
            Câu này đã có học sinh làm, nên hệ thống giữ nguyên câu cũ cho các lượt đã làm và lưu nội dung mới thành câu mới ở cùng vị trí. Học sinh làm từ bây giờ sẽ thấy nội dung mới.
          </Alert>
        ) : null}

        <fieldset disabled={saving} className="flex flex-col gap-5">
          {toolbar}

          <Field
            id={fieldId("content")}
            label="Nội dung câu hỏi"
            required
            error={errors.content ?? warn(content)}
            aside={<span className="num text-ink-soft">{content.length.toLocaleString("vi-VN")}/{QUIZ_LIMITS.content.toLocaleString("vi-VN")}</span>}
          >
            <Textarea
              ref={(el) => {
                refs.current.content = el;
              }}
              rows={5}
              maxLength={QUIZ_LIMITS.content}
              value={content}
              onFocus={() => {
                lastFocused.current = "content";
              }}
              onChange={(e) => setContent(e.target.value)}
              placeholder="Ví dụ: Cho $\widehat{BAC} = 35^\circ$. Số đo cung $BC$ là:"
            />
          </Field>

          <fieldset className="flex flex-col gap-3">
            <legend className="mb-1 text-sm font-semibold text-ink">
              Đáp án <span className="font-normal text-ink-soft">— chọn đúng 1 đáp án đúng</span>
            </legend>
            {errors.correct ? <p className="text-sm font-medium text-danger">{errors.correct}</p> : null}
            {options.map((o, i) => {
              const k = `o${i}` as FieldKey;
              const isCorrect = correct === i;
              return (
                <div key={k} className={cx("flex flex-col gap-2 rounded-card border p-3", isCorrect ? "border-success bg-success-soft" : "border-line bg-surface")}>
                  <div className="flex items-center justify-between gap-2">
                    <span className="flex items-center gap-2 text-sm font-semibold text-ink">
                      <span className={cx("flex size-7 items-center justify-center rounded-full border-2 font-extrabold", isCorrect ? "border-success bg-success text-on-status" : "border-line-strong text-ink")}>
                        {LETTERS[i]}
                      </span>
                      Đáp án {LETTERS[i]}
                    </span>
                    <label className="flex min-h-9 cursor-pointer items-center gap-2 rounded-control px-2 text-sm font-semibold text-ink has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-offset-2 has-[input:focus-visible]:outline-focus">
                      <input
                        id={i === 0 ? fieldId("o0-dung") : undefined}
                        type="radio"
                        name="correct"
                        checked={isCorrect}
                        onChange={() => {
                          setCorrect(i);
                          setErrors((er) => ({ ...er, correct: undefined }));
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
                    error={errors[k] ?? warn(o)}
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
                      onChange={(e) => setters[k](e.target.value)}
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
            hint="Không bắt buộc. Học sinh thấy sau khi nộp bài."
            error={errors.explanation ?? warn(explanation)}
            aside={<span className="num text-ink-soft">{explanation.length.toLocaleString("vi-VN")}/{QUIZ_LIMITS.explanation.toLocaleString("vi-VN")}</span>}
          >
            <Textarea
              ref={(el) => {
                refs.current.explanation = el;
              }}
              rows={4}
              maxLength={QUIZ_LIMITS.explanation}
              value={explanation}
              onFocus={() => {
                lastFocused.current = "explanation";
              }}
              onChange={(e) => setExplanation(e.target.value)}
            />
          </Field>
        </fieldset>

        <div className="sticky bottom-0 -mx-4 flex flex-wrap items-center gap-3 border-t border-line bg-surface px-4 py-3 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 xl:static xl:mx-0 xl:border-0 xl:bg-transparent xl:px-0">
          <Button type="submit" size="sm" loading={saving} loadingText="Đang lưu…">
            {isNew ? "Thêm câu hỏi" : "Lưu câu hỏi"}
          </Button>
          <Link href={listHref} className="focus-ring inline-flex h-9 items-center rounded-control px-3 text-sm font-semibold text-ink hover:bg-sunken">
            Về danh sách câu
          </Link>
          <span aria-live="polite" className="text-sm text-ink-soft">
            {savedAt ? (
              <span className="inline-flex items-center gap-1 text-success">
                <IconCheckCircle size={16} className="motion-safe:animate-tick" /> Đã lưu lúc {savedAt}
              </span>
            ) : null}
          </span>
          {!isNew ? (
            <Button type="button" variant="ghost" size="sm" className="ml-auto text-danger hover:bg-danger-soft" leadingIcon={<IconTrash size={16} />} onClick={() => setConfirmDelete(true)}>
              Xoá câu
            </Button>
          ) : null}
        </div>
      </form>

      <aside aria-labelledby="xem-truoc" className="min-w-0 xl:sticky xl:top-6 xl:self-start">
        <div className="flex items-center justify-between gap-2">
          <h2 id="xem-truoc" className="text-base font-semibold text-ink">
            Xem trước — học sinh sẽ thấy
          </h2>
          <Badge size="sm">Cập nhật khi gõ</Badge>
        </div>
        <div className="mt-3 flex flex-col gap-4 rounded-card border border-line bg-surface p-4 sm:p-5">
          <p className="text-sm font-semibold text-ink-soft">Câu {position}</p>
          {content.trim() ? (
            <MathText content={content} className="text-question text-ink" />
          ) : (
            <p className="text-base italic text-ink-soft">Nội dung câu hỏi sẽ hiện ở đây.</p>
          )}
          <ul className="flex flex-col gap-2">
            {options.map((o, i) => (
              <li
                key={i}
                className={cx(
                  "flex min-h-14 items-center gap-3 rounded-control border px-3 py-2",
                  correct === i ? "border-success bg-success-soft" : "border-line-strong",
                )}
              >
                <span className={cx("flex size-8 shrink-0 items-center justify-center rounded-full border-2 font-extrabold", correct === i ? "border-success bg-success text-on-status" : "border-line-strong text-ink")}>
                  {LETTERS[i]}
                </span>
                <span className="min-w-0 flex-1 text-base text-ink">{o.trim() ? <MathText as="span" content={o} /> : <span className="italic text-ink-soft">Chưa có nội dung</span>}</span>
                {correct === i ? <span className="shrink-0 text-sm font-semibold text-success">Đáp án đúng</span> : null}
              </li>
            ))}
          </ul>
          {explanation.trim() ? (
            <div className="rounded-card bg-sunken p-4">
              <p className="text-sm font-semibold text-ink">Lời giải</p>
              <MathText content={explanation} className="mt-1 text-base leading-relaxed text-ink" />
            </div>
          ) : null}
        </div>
        <p className="mt-2 text-sm text-ink-soft">Bản xem trước dùng công thức của trình duyệt; bản thật dùng KaTeX giống hệt trang học sinh. Công thức viết sai cú pháp sẽ hiện nguyên văn màu đỏ.</p>
      </aside>

      <ConfirmDialog
        open={confirmDelete}
        onClose={() => setConfirmDelete(false)}
        onConfirm={() => setConfirmDelete(false)}
        tone="danger"
        title={`Xoá câu ${position}?`}
        description="Câu hỏi sẽ bị xoá khỏi bài tập này. Các câu phía sau được đánh số lại."
        confirmLabel="Xoá câu"
      />
    </div>
  );
}
