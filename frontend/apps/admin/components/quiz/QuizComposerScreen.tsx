"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import {
  Alert,
  Breadcrumb,
  Button,
  ButtonLink,
  EmptyState,
  IconClock,
  IconLayers,
  IconListChecks,
  IconLock,
  IconPencil,
  IconPlus,
  IconSearch,
  LoadingRegion,
  Skeleton,
  cx,
  useToast,
} from "@vitaminvui/ui/v2";
import { useSession } from "@/lib/auth/SessionProvider";
import { getCourse } from "@/lib/courses/api";
import { isCourseStaff } from "@/lib/courses/permissions";
import { COURSES_PATH } from "@/lib/courses/query";
import { clockVN } from "@/lib/quiz/logic";
import { useUnsavedChangesGuard } from "@/lib/unsaved/useUnsavedChangesGuard";
import { getQuiz, reorderQuestions } from "@/lib/quiz/api";
import { isForbidden, isGone, isQuestionsMismatch, quizError } from "@/lib/quiz/errors";
import { QUIZ_LIMITS, type QuizDetail, type QuizItem, type QuizQuestion } from "@/lib/quiz/types";
import { QuestionList } from "./QuestionList";
import { renumberQuestions } from "@/lib/quiz/order";
import { QuestionEditor, type SaveNotice } from "./QuestionEditor";
import { QuizSettingsDialog } from "./QuizSettingsDialog";

/** `?cau=` hợp lệ: `moi` hoặc id số nguyên dương. */
export function parseCau(raw: string | null): "moi" | number | null {
  if (raw === "moi") return "moi";
  return raw && /^[1-9]\d{0,9}$/.test(raw) ? Number(raw) : null;
}

/**
 * `/quan-tri/khoa-hoc/{id}/bai-tap/{quiz}` — danh sách câu (thứ tự học sinh làm) hoặc, với `?cau=ID|moi`, khung soạn một câu có
 * xem trước KaTeX. Quyền thật do Policy `manageContent` (403/404 hiện trạng thái riêng); ở đây chỉ thay nhãn theo vai trò.
 */
export function QuizComposerScreen({ courseId, quizId }: { courseId: number; quizId: number }) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const toast = useToast();
  const { state } = useSession();
  const ready = state.kind === "staff";
  const cau = parseCau(searchParams.get("cau"));
  const [quiz, setQuiz] = useState<QuizDetail | null>(null);
  const [courseTitle, setCourseTitle] = useState<string | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [tick, setTick] = useState(0);
  const [editSettings, setEditSettings] = useState(false);
  const [notice, setNotice] = useState<(SaveNotice & { questionId: number }) | null>(null);
  const [reordering, setReordering] = useState(false);
  const reorderingRef = useRef(false);
  const lastToastAt = useRef(0);
  const [actionError, setActionError] = useState<string | null>(null);
  const { dirtyRef, setDirty, rearm, dialog: leaveDialog } = useUnsavedChangesGuard({
    guardHistory: true,
    description: "Câu hỏi bạn vừa sửa chưa được lưu. Rời trang này sẽ bỏ các thay đổi đó.",
  });

  useEffect(() => {
    if (!ready) return;
    const c = new AbortController();
    getQuiz(courseId, quizId, c.signal)
      .then((q) => {
        setQuiz(q);
        setError(null);
      })
      .catch((err: unknown) => {
        if (c.signal.aborted) return;
        // Quiz đã bị xoá / mất quyền (ở tab khác): bỏ dữ liệu cũ để ra màn 404/403, không giữ trang cũ.
        if (isGone(err) || isForbidden(err)) setQuiz(null);
        setError(err);
      });
    getCourse(courseId, c.signal)
      .then((course) => setCourseTitle(course.title))
      .catch(() => undefined);
    return () => c.abort();
  }, [courseId, quizId, ready, tick]);

  const base = `/quan-tri/khoa-hoc/${courseId}/bai-tap/${quizId}`;
  const listHref = base;
  const caseHref = (c: number | "moi") => `${base}?cau=${c}`;
  const courseTab = `${COURSES_PATH}/${courseId}/sua?tab=bai-tap`;

  async function reorder(ids: number[]) {
    if (reorderingRef.current || !quiz) return;
    const prev = quiz.questions;
    const byId = new Map(prev.map((q) => [q.id, q]));
    reorderingRef.current = true;
    setReordering(true);
    setActionError(null);
    setQuiz((q) => (q ? { ...q, questions: renumberQuestions(ids.map((id) => byId.get(id)!)) } : q)); // lạc quan
    try {
      const saved = await reorderQuestions(courseId, quizId, ids);
      setQuiz((q) => (q ? { ...q, questions: saved } : q));
      // Bấm nhanh nhiều lần: chỉ một toast trong 3 giây (tránh chồng toast).
      if (Date.now() - lastToastAt.current > 3000) {
        lastToastAt.current = Date.now();
        toast.show({ tone: "success", title: "Đã lưu thứ tự câu" });
      }
    } catch (err) {
      setQuiz((q) => (q ? { ...q, questions: prev } : q));
      if (isQuestionsMismatch(err) || isGone(err) || isForbidden(err)) setTick((n) => n + 1);
      setActionError(quizError(err));
    } finally {
      reorderingRef.current = false;
      setReordering(false);
      setTick((n) => n + 1); // luôn đồng bộ lại với server sau khi đổi thứ tự (thành công hay lỗi)
    }
  }

  if (!ready) return null;
  const isStaff = isCourseStaff(state.user);
  const listLabel = isStaff ? "Khóa học" : "Khóa học của tôi";
  const backToTab = (
    <ButtonLink href={courseTab} variant="secondary" size="sm">
      Về danh sách bài tập
    </ButtonLink>
  );

  if (!quiz && error) {
    if (isForbidden(error)) {
      return (
        <div data-testid="quiz-forbidden" className="mx-auto max-w-xl py-8">
          <EmptyState headingLevel="h1" icon={<IconLock size={32} />} title="Bạn không có quyền sửa bài tập của khóa học này." description="Chỉ giáo viên được gán và quản trị viên mới sửa được bài tập." action={<ButtonLink href={COURSES_PATH} variant="secondary" size="sm">Về danh sách khóa học</ButtonLink>} />
        </div>
      );
    }
    if (isGone(error)) {
      return (
        <div data-testid="quiz-not-found" className="mx-auto max-w-xl py-8">
          <EmptyState headingLevel="h1" icon={<IconSearch size={32} />} title="Không tìm thấy bài tập" description="Bài tập hoặc khóa học có thể đã bị xoá." action={backToTab} />
        </div>
      );
    }
    return (
      <Alert
        tone="danger"
        title="Không tải được bài tập"
        action={
          <Button size="sm" variant="secondary" className="max-sm:h-11" onClick={() => setTick((n) => n + 1)}>
            Thử lại
          </Button>
        }
      >
        {quizError(error)}
      </Alert>
    );
  }
  if (!quiz) {
    return (
      <LoadingRegion className="flex flex-col gap-4">
        <Skeleton className="h-5 w-64" />
        <Skeleton className="h-9 w-80 max-w-full" />
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-24 w-full" />
      </LoadingRegion>
    );
  }

  const questions = quiz.questions;
  const full = questions.length >= QUIZ_LIMITS.questions;
  const idx = typeof cau === "number" ? questions.findIndex((q) => q.id === cau) : -1;
  const current: QuizQuestion | null = idx >= 0 ? (questions[idx] ?? null) : null;
  const editing = cau !== null;
  const missing = typeof cau === "number" && !current;
  const number = current ? idx + 1 : questions.length + 1;

  const setQuestions = (next: QuizQuestion[]) => setQuiz((q) => (q ? { ...q, questions: next, questions_count: next.length } : q));
  const parentLabel = quiz.parent_type === "chapter" ? "Cuối chương: " : "Bài học: ";

  return (
    <div className="flex flex-col">
      <Breadcrumb
        items={[
          { label: listLabel, href: COURSES_PATH },
          { label: courseTitle ?? `Khóa học #${courseId}`, href: courseTab },
          ...(editing ? [{ label: quiz.title, href: listHref }, { label: current ? `Câu ${number}` : "Câu mới" }] : [{ label: quiz.title }]),
        ]}
      />

      <div className="mt-3 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div className="min-w-0">
          <h1 className="break-words text-title font-extrabold tracking-heading text-ink">{quiz.title}</h1>
          <ul className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink-soft">
            <li className="inline-flex items-center gap-1.5">
              <IconLayers size={16} />
              {parentLabel}
              {quiz.parent_title}
            </li>
            <li className="inline-flex items-center gap-1.5">
              <IconClock size={16} />
              {quiz.time_limit_minutes ? `${quiz.time_limit_minutes} phút` : "Không giới hạn thời gian"}
            </li>
            <li className="num inline-flex items-center gap-1.5" data-testid="question-count">
              <IconListChecks size={16} />
              {questions.length}/{QUIZ_LIMITS.questions} câu
            </li>
          </ul>
        </div>
        {!editing ? (
          <div className="flex shrink-0 flex-wrap gap-2">
            <Button size="sm" variant="secondary" className="max-sm:h-11" leadingIcon={<IconPencil size={16} />} onClick={() => setEditSettings(true)}>
              Sửa thông tin
            </Button>
            {full || reordering ? (
              <Button size="sm" className="max-sm:h-11" disabled leadingIcon={<IconPlus size={16} />}>
                Thêm câu hỏi
              </Button>
            ) : (
              <ButtonLink href={caseHref("moi")} size="sm" className="max-sm:h-11" leadingIcon={<IconPlus size={16} />}>
                Thêm câu hỏi
              </ButtonLink>
            )}
          </div>
        ) : null}
      </div>
      {full && !editing ? <p className="mt-2 text-sm text-ink-soft lg:text-right">Mỗi bài tập tối đa 200 câu. Hãy tạo bài tập mới cho phần còn lại.</p> : null}

      {editing ? (
        <>
          <nav aria-label="Chọn câu để soạn" className="mt-5 flex flex-wrap gap-1.5">
            {questions.map((q, i) => (
              <Link
                key={q.id}
                href={caseHref(q.id)}
                scroll={false}
                aria-current={q.id === current?.id ? "page" : undefined}
                className={cx(
                  "focus-ring num inline-flex size-9 items-center justify-center rounded-control border text-sm font-semibold max-sm:size-11",
                  q.id === current?.id ? "border-primary bg-primary text-on-primary" : "border-line-strong bg-surface text-ink hover:border-primary hover:text-primary",
                )}
              >
                <span className="sr-only">Câu </span>
                {i + 1}
              </Link>
            ))}
            {!full ? (
              <Link
                href={caseHref("moi")}
                scroll={false}
                aria-current={cau === "moi" ? "page" : undefined}
                className={cx(
                  "focus-ring inline-flex h-9 items-center gap-1 rounded-control border border-dashed px-2.5 text-sm font-semibold max-sm:h-11",
                  cau === "moi" ? "border-primary bg-primary-soft text-primary" : "border-line-strong text-primary hover:border-primary",
                )}
              >
                <IconPlus size={16} /> Câu mới
              </Link>
            ) : null}
          </nav>
          <div className="mt-5">
            {missing ? (
              <div data-testid="question-missing">
                <Alert tone="warning" title="Không tìm thấy câu hỏi này" action={<ButtonLink href={listHref} size="sm" variant="secondary">Về danh sách câu</ButtonLink>}>
                  Câu có thể đã bị xoá, hoặc đã được thay bằng bản mới ở cùng vị trí. Hãy chọn lại câu từ danh sách.
                </Alert>
              </div>
            ) : cau === "moi" && full ? (
              <Alert tone="info" title="Bài tập đã đủ 200 câu" action={<ButtonLink href={listHref} size="sm" variant="secondary">Về danh sách câu</ButtonLink>}>
                Mỗi bài tập tối đa 200 câu. Hãy tạo bài tập mới cho phần còn lại.
              </Alert>
            ) : (
              <QuestionEditor
                key={current ? `q${current.id}` : "moi"}
                courseId={courseId}
                quizId={quizId}
                question={current}
                number={number}
                prevHref={idx > 0 ? caseHref(questions[idx - 1]!.id) : undefined}
                nextHref={idx >= 0 && idx < questions.length - 1 ? caseHref(questions[idx + 1]!.id) : undefined}
                listHref={listHref}
                notice={notice && notice.questionId === current?.id ? notice : null}
                onDirtyChange={setDirty}
                onSaved={(saved, info) => {
                  const stamp = { at: clockVN(new Date()), replaced: info.replacedId !== null, questionId: saved.id };
                  setNotice(stamp);
                  if (info.created) {
                    setQuestions([...questions, saved]);
                    dirtyRef.current = false;
                    router.replace(`${pathname}?cau=${saved.id}`, { scroll: false });
                    rearm();
                  } else {
                    setQuestions(questions.map((q) => (q.id === (info.replacedId ?? saved.id) ? saved : q)));
                    if (info.replacedId !== null) {
                      dirtyRef.current = false;
                      router.replace(`${pathname}?cau=${saved.id}`, { scroll: false });
                      rearm();
                    }
                  }
                  toast.show({ tone: "success", title: info.created ? "Đã thêm câu hỏi" : info.replacedId !== null ? "Đã lưu thành bản mới" : "Đã lưu câu hỏi" });
                }}
                onDeleted={(id) => {
                  setQuestions(questions.filter((q) => q.id !== id));
                  dirtyRef.current = false;
                  toast.show({ tone: "success", title: "Đã xoá câu hỏi" });
                  router.replace(listHref, { scroll: false });
                }}
                onGone={() => {
                  dirtyRef.current = false;
                  toast.show({ tone: "warning", title: "Câu hỏi không còn tồn tại. Đã tải lại danh sách." });
                  setTick((n) => n + 1);
                  router.replace(listHref, { scroll: false });
                }}
              />
            )}
          </div>
        </>
      ) : questions.length === 0 ? (
        <div className="mt-6 rounded-card border border-line bg-surface">
          <EmptyState
            icon={<IconListChecks size={36} />}
            title="Bài tập chưa có câu hỏi"
            description="Học sinh mở bài tập này sẽ thấy “Bài kiểm tra chưa sẵn sàng”. Thêm ít nhất một câu trắc nghiệm 4 đáp án."
            action={
              <ButtonLink href={caseHref("moi")} leadingIcon={<IconPlus size={18} />}>
                Thêm câu hỏi đầu tiên
              </ButtonLink>
            }
          />
        </div>
      ) : (
        <>
          <p className="mt-5 text-sm text-ink-soft">Câu hiện theo thứ tự học sinh sẽ làm. Kéo biểu tượng ⠿ (hoặc dùng nút Lên/Xuống) để đổi thứ tự; câu mới luôn thêm vào cuối. Lượt làm đang dở giữ thứ tự cũ, lượt mới theo thứ tự mới.</p>
          {actionError ? (
            <Alert
              tone="danger"
              className="mt-3"
              action={
                <Button size="sm" variant="secondary" className="max-sm:h-11" onClick={() => setActionError(null)}>
                  Đóng
                </Button>
              }
            >
              {actionError}
            </Alert>
          ) : null}
          <QuestionList questions={questions} caseHref={caseHref} locked={reordering} onReorder={(ids) => void reorder(ids)} />
        </>
      )}

      {editSettings ? (
        <QuizSettingsDialog
          courseId={courseId}
          quiz={quiz as QuizItem}
          onClose={() => setEditSettings(false)}
          onSaved={(saved, info) => {
            setEditSettings(false);
            setQuiz((q) => (q ? { ...q, ...saved, questions: q.questions, parent_title: saved.parent_title ?? q.parent_title } : q));
            setTick((n) => n + 1);
            toast.show({ tone: "success", title: "Đã lưu thông tin bài tập" });
            if (info.timeIgnored) toast.show({ tone: "info", title: "Giới hạn thời gian đang tắt trên hệ thống nên chưa được áp dụng" });
          }}
        />
      ) : null}
      {leaveDialog}
    </div>
  );
}
