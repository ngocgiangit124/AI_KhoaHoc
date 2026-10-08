"use client";

import { useEffect, useRef, useState } from "react";
import { Button, Checkbox, IconAlertCircle, IconX, TextInput } from "@vitaminvui/ui/v2";
import { SCOPE_MAX, type PickedCourse, type ScopeKind } from "@/lib/coupons/form";
import { couponActionError } from "@/lib/coupons/errors";
import { listAllSubjects, searchCourses, type CourseChoice, type SubjectChoice } from "@/lib/coupons/options";

export interface CouponScopeSelectorProps {
  scope: ScopeKind;
  onScope: (s: ScopeKind) => void;
  subjectIds: number[];
  onSubjects: (ids: number[]) => void;
  courses: PickedCourse[];
  onCourses: (c: PickedCourse[]) => void;
  /** Tên chuyên đề đã biết từ chi tiết mã (để hiện kể cả khi danh sách chưa tải xong). */
  knownSubjects?: Array<{ id: number; name: string }>;
  errors: { subject_ids?: string; course_ids?: string };
  onClearError: (k: "subject_ids" | "course_ids") => void;
  disabled?: boolean;
  /** Mã cũ có cả khóa lẫn chuyên đề: luôn hiện lựa chọn "Kết hợp" để không mất phạm vi dù đã chuyển radio. */
  legacyBoth?: boolean;
}

const OPTIONS: Array<{ v: ScopeKind; label: string }> = [
  { v: "all", label: "Toàn bộ khóa học" },
  { v: "subjects", label: "Theo chuyên đề cụ thể" },
  { v: "courses", label: "Theo khóa học cụ thể" },
];
const STATUS_NOTE = { draft: "nháp", published: "", unpublished: "ngừng bán" } as const;

/** Phạm vi áp dụng (US-013 §2.2): 3 lựa chọn + ô chọn tương ứng. Mã cũ có cả khóa lẫn chuyên đề hiện thêm lựa chọn "Kết hợp". */
export function CouponScopeSelector({ scope, onScope, subjectIds, onSubjects, courses, onCourses, knownSubjects = [], errors, onClearError, disabled, legacyBoth = false }: CouponScopeSelectorProps) {
  const showSubjects = scope === "subjects" || scope === "both";
  const showCourses = scope === "courses" || scope === "both";
  const options = scope === "both" || legacyBoth ? [...OPTIONS, { v: "both" as const, label: "Kết hợp chuyên đề và khóa học" }] : OPTIONS;

  return (
    <div className="flex flex-col gap-4">
      <fieldset className="flex flex-col gap-1" disabled={disabled}>
        <legend className="sr-only">Phạm vi áp dụng</legend>
        {options.map((o) => (
          <label key={o.v} className="flex min-h-11 cursor-pointer items-center gap-3 text-base text-ink">
            <input type="radio" name="scope" value={o.v} checked={scope === o.v} onChange={() => onScope(o.v)} className="size-5 accent-primary" />
            {o.label}
          </label>
        ))}
      </fieldset>
      {scope === "all" ? <p className="text-sm text-ink-soft">Mã áp dụng cho mọi khóa học đang bán.</p> : null}
      {showSubjects ? <SubjectPicker ids={subjectIds} onChange={onSubjects} known={knownSubjects} error={errors.subject_ids} onClear={() => onClearError("subject_ids")} disabled={disabled} /> : null}
      {showCourses ? <CoursePicker picked={courses} onChange={onCourses} error={errors.course_ids} onClear={() => onClearError("course_ids")} disabled={disabled} /> : null}
    </div>
  );
}

function ErrorLine({ id, children }: { id: string; children: string }) {
  return (
    <p id={id} className="flex items-start gap-1.5 text-sm font-medium text-danger">
      <IconAlertCircle size={16} className="mt-0.5 shrink-0" />
      <span>{children}</span>
    </p>
  );
}

function SubjectPicker({ ids, onChange, known, error, onClear, disabled }: { ids: number[]; onChange: (ids: number[]) => void; known: Array<{ id: number; name: string }>; error?: string; onClear: () => void; disabled?: boolean }) {
  const [attempt, setAttempt] = useState(0);
  const [state, setState] = useState<{ attempt: number; items: SubjectChoice[]; error: string | null } | null>(null);
  useEffect(() => {
    const controller = new AbortController();
    listAllSubjects(controller.signal)
      .then((items) => setState({ attempt, items, error: null }))
      .catch((err: unknown) => {
        if (!controller.signal.aborted) setState({ attempt, items: [], error: couponActionError(err) });
      });
    return () => controller.abort();
  }, [attempt]);
  const done = state !== null && state.attempt === attempt;
  const loaded = done ? state.items : [];
  // Chuyên đề đã chọn mà danh sách không có (vừa bị xoá) vẫn hiện để bỏ chọn.
  const extra = ids.filter((id) => !loaded.some((s) => s.id === id)).map((id) => ({ id, name: known.find((k) => k.id === id)?.name ?? `Chuyên đề #${id}`, hidden: false }));
  const items = done && !state.error ? [...loaded, ...extra] : [];

  return (
    <div
      id="cp-subject_ids"
      tabIndex={-1}
      data-invalid={error ? "true" : undefined}
      aria-describedby={error ? "cp-subject_ids-error" : undefined}
      className="flex flex-col gap-2 rounded-control border border-line p-3 outline-none"
    >
      <p className="text-sm font-semibold text-ink">
        Chuyên đề <span className="ml-1 font-normal text-ink-soft">Đã chọn {ids.length}</span>
      </p>
      {!done ? (
        <p className="text-sm text-ink-soft" role="status">
          Đang tải chuyên đề…
        </p>
      ) : state.error ? (
        <div className="text-sm text-danger" role="alert">
          <p>{state.error}</p>
          <Button size="sm" variant="secondary" className="mt-1 max-sm:h-11" onClick={() => setAttempt((n) => n + 1)}>
            Thử lại
          </Button>
        </div>
      ) : items.length === 0 ? (
        <p className="text-sm text-ink-soft">Chưa có chuyên đề nào. Hãy tạo ở mục Chuyên đề.</p>
      ) : (
        <div className="grid max-h-64 gap-x-4 overflow-y-auto sm:grid-cols-2">
          {items.map((s) => (
            <Checkbox
              key={s.id}
              id={`cp-subject-${s.id}`}
              label={
                <>
                  {s.name}
                  {s.hidden ? <span className="ml-1 text-sm text-ink-soft">(đang ẩn)</span> : null}
                </>
              }
              className="min-h-11 py-1.5 sm:min-h-10"
              disabled={disabled}
              checked={ids.includes(s.id)}
              onChange={() => {
                onChange(ids.includes(s.id) ? ids.filter((x) => x !== s.id) : [...ids, s.id]);
                onClear();
              }}
            />
          ))}
        </div>
      )}
      {error ? <ErrorLine id="cp-subject_ids-error">{error}</ErrorLine> : null}
    </div>
  );
}

const SEARCH_DEBOUNCE_MS = 300;

function CoursePicker({ picked, onChange, error, onClear, disabled }: { picked: PickedCourse[]; onChange: (c: PickedCourse[]) => void; error?: string; onClear: () => void; disabled?: boolean }) {
  const [q, setQ] = useState("");
  const [term, setTerm] = useState("");
  const [result, setResult] = useState<{ term: string; attempt: number; items: CourseChoice[]; error: string | null } | null>(null);
  const [attempt, setAttempt] = useState(0);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    timer.current = setTimeout(() => setTerm(q.trim()), SEARCH_DEBOUNCE_MS);
    return () => {
      if (timer.current) clearTimeout(timer.current);
    };
  }, [q]);

  useEffect(() => {
    const controller = new AbortController();
    searchCourses(term, controller.signal)
      .then((items) => setResult({ term, attempt, items, error: null }))
      .catch((err: unknown) => {
        if (!controller.signal.aborted) setResult({ term, attempt, items: [], error: couponActionError(err) });
      });
    return () => controller.abort();
  }, [term, attempt]);

  const loading = result === null || result.term !== term || result.attempt !== attempt;
  const isPicked = (id: number) => picked.some((c) => c.id === id);
  const toggle = (c: CourseChoice) => {
    if (isPicked(c.id)) onChange(picked.filter((x) => x.id !== c.id));
    else if (picked.length < SCOPE_MAX) onChange([...picked, { id: c.id, title: c.title }]);
    onClear();
  };

  return (
    <div
      id="cp-course_ids"
      tabIndex={-1}
      data-invalid={error ? "true" : undefined}
      aria-describedby={error ? "cp-course_ids-error" : undefined}
      className="flex flex-col gap-3 rounded-control border border-line p-3 outline-none"
    >
      <p className="text-sm font-semibold text-ink">
        Khóa học <span className="ml-1 font-normal text-ink-soft">Đã chọn {picked.length}/{SCOPE_MAX}</span>
      </p>
      {picked.length > 0 ? (
        <ul aria-label="Khóa học đã chọn" className="flex flex-wrap gap-2">
          {picked.map((c) => (
            <li key={c.id} className="flex max-w-full items-center gap-1 rounded-control bg-primary-soft py-0.5 pl-3 pr-1 text-sm text-ink">
              <span className="min-w-0 truncate">{c.title}</span>
              <button
                type="button"
                disabled={disabled}
                aria-label={`Bỏ khóa ${c.title}`}
                onClick={() => onChange(picked.filter((x) => x.id !== c.id))}
                className="focus-ring inline-flex size-11 shrink-0 items-center justify-center rounded-control text-ink-soft hover:text-danger sm:size-8"
              >
                <IconX size={16} />
              </button>
            </li>
          ))}
        </ul>
      ) : null}
      <label htmlFor="cp-course-q" className="sr-only">
        Tìm khóa học theo tên
      </label>
      <TextInput
        id="cp-course-q"
        size="sm"
        className="max-sm:h-11"
        type="search"
        autoComplete="off"
        placeholder="Tìm khóa học theo tên"
        value={q}
        maxLength={100}
        disabled={disabled}
        onChange={(e) => setQ(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === "Enter") e.preventDefault();
        }}
      />
      <div aria-busy={loading} className="max-h-64 overflow-y-auto">
        {result?.error && !loading ? (
          <div className="text-sm text-danger" role="alert">
            <p>{result.error}</p>
            <Button size="sm" variant="secondary" className="mt-1 max-sm:h-11" onClick={() => setAttempt((n) => n + 1)}>
              Thử lại
            </Button>
          </div>
        ) : loading && !result ? (
          <p className="text-sm text-ink-soft" role="status">
            Đang tải khóa học…
          </p>
        ) : result && result.items.length === 0 ? (
          <p className="text-sm text-ink-soft">{term ? "Không có khóa học nào khớp." : "Chưa có khóa học nào."}</p>
        ) : (
          result?.items.map((c) => (
            <Checkbox
              key={c.id}
              id={`cp-course-${c.id}`}
              label={
                <>
                  {c.title}
                  {STATUS_NOTE[c.status] ? <span className="ml-1 text-sm text-ink-soft">({STATUS_NOTE[c.status]})</span> : null}
                </>
              }
              className="min-h-11 py-1.5 sm:min-h-10"
              disabled={disabled || (!isPicked(c.id) && picked.length >= SCOPE_MAX)}
              checked={isPicked(c.id)}
              onChange={() => toggle(c)}
            />
          ))
        )}
      </div>
      <p className="text-xs text-ink-soft">Hiện tối đa 25 kết quả đầu; gõ tên để thu hẹp.</p>
      {error ? <ErrorLine id="cp-course_ids-error">{error}</ErrorLine> : null}
    </div>
  );
}
