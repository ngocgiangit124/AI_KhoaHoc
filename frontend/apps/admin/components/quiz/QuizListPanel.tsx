"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { Alert, Badge, Button, ConfirmDialog, DataTable, EmptyState, IconArrowRight, IconListChecks, IconLock, IconPlus, IconSearch, IconTrash, LoadingRegion, Skeleton, useToast } from "@vitaminvui/ui/v2";
import { deleteQuiz, listQuizzes } from "@/lib/quiz/api";
import { isForbidden, isGone, quizError } from "@/lib/quiz/errors";
import type { QuizItem } from "@/lib/quiz/types";
import { QuizSettingsDialog } from "./QuizSettingsDialog";

export const quizHref = (courseId: number, quizId: number) => `/quan-tri/khoa-hoc/${courseId}/bai-tap/${quizId}`;

type Dialog = { kind: "create" } | { kind: "edit"; quiz: QuizItem } | { kind: "delete"; quiz: QuizItem };

/**
 * Tab "Bài tập" của màn sửa khóa (US-009, design-system-v2 §14.1): bảng bài tập theo thứ tự `position` (tên, gắn với, số câu,
 * thời gian). Bài tập chưa có câu → badge cảnh báo (học sinh sẽ thấy "Bài kiểm tra chưa sẵn sàng").
 */
export function QuizListPanel({ courseId, onCountChange }: { courseId: number; onCountChange?: (n: number) => void }) {
  const toast = useToast();
  const [quizzes, setQuizzes] = useState<QuizItem[] | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const [busy, setBusy] = useState(false);
  const busyRef = useRef(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [tick, setTick] = useState(0);
  const countRef = useRef(onCountChange);
  useEffect(() => {
    countRef.current = onCountChange;
  });

  const commit = useCallback((next: QuizItem[]) => {
    setQuizzes(next);
    countRef.current?.(next.length);
  }, []);

  useEffect(() => {
    const c = new AbortController();
    listQuizzes(courseId, c.signal)
      .then((list) => {
        setQuizzes(list);
        setError(null);
        countRef.current?.(list.length);
      })
      .catch((err: unknown) => {
        if (!c.signal.aborted) setError(err);
      });
    return () => c.abort();
  }, [courseId, tick]);

  if (error && quizzes === null) {
    if (isForbidden(error)) {
      return (
        <div data-testid="quiz-forbidden" className="mx-auto max-w-xl py-8">
          <EmptyState icon={<IconLock size={32} />} title="Bạn không có quyền sửa bài tập của khóa học này." description="Chỉ giáo viên được gán và quản trị viên mới sửa được bài tập." />
        </div>
      );
    }
    if (isGone(error)) return <EmptyState icon={<IconSearch size={32} />} title="Không tìm thấy khóa học" description="Khóa học có thể đã bị xoá." />;
    return (
      <Alert
        tone="danger"
        title="Không tải được danh sách bài tập"
        action={
          <Button size="sm" variant="secondary" className="max-sm:h-11" onClick={() => setTick((n) => n + 1)}>
            Thử lại
          </Button>
        }
      >
        {quizError(error)} Các phần khác của khóa học không bị ảnh hưởng.
      </Alert>
    );
  }
  if (quizzes === null) {
    return (
      <LoadingRegion className="flex flex-col gap-3">
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-40 w-full" />
      </LoadingRegion>
    );
  }

  const empties = quizzes.filter((q) => (q.questions_count ?? 0) === 0).length;
  const createButton = (
    <Button size="sm" className="max-sm:h-11" leadingIcon={<IconPlus size={16} />} onClick={() => setDialog({ kind: "create" })}>
      Tạo bài tập
    </Button>
  );

  async function confirmDelete(quiz: QuizItem) {
    if (busyRef.current) return;
    busyRef.current = true;
    setBusy(true);
    try {
      await deleteQuiz(courseId, quiz.id);
      commit((quizzes ?? []).filter((q) => q.id !== quiz.id));
      toast.show({ tone: "success", title: "Đã xoá bài tập" });
    } catch (err) {
      if (isGone(err)) setTick((n) => n + 1);
      setActionError(quizError(err));
    } finally {
      busyRef.current = false;
      setBusy(false);
      setDialog(null);
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <p className="max-w-2xl text-sm text-ink-soft">Mỗi bài tập đi kèm một chương (bài cuối chương) hoặc một bài học. Học sinh thấy bài tập ngay dưới chương/bài đó ở trang học.</p>
        {createButton}
      </div>
      {actionError ? (
        <Alert
          tone="danger"
          action={
            <Button size="sm" variant="secondary" className="max-sm:h-11" onClick={() => setActionError(null)}>
              Đóng
            </Button>
          }
        >
          {actionError}
        </Alert>
      ) : null}
      {empties > 0 ? (
        <Alert tone="warning" title={`${empties} bài tập chưa có câu hỏi`}>
          Học sinh mở bài tập chưa có câu sẽ thấy “Bài kiểm tra chưa sẵn sàng”. Hãy thêm câu hỏi hoặc xoá bài tập đó.
        </Alert>
      ) : null}
      <DataTable
        caption="Danh sách bài tập của khóa học"
        rowKey={(q) => q.id}
        rows={quizzes}
        empty={
          <EmptyState
            size="inline"
            icon={<IconListChecks size={28} />}
            title="Khóa học chưa có bài tập"
            description="Tạo bài tập trắc nghiệm cho một chương hoặc một bài học. Câu hỏi viết được công thức Toán."
            action={createButton}
          />
        }
        columns={[
          {
            key: "title",
            header: "Bài tập",
            cell: (q) => (
              <div className="flex flex-col">
                <Link href={quizHref(courseId, q.id)} className="focus-ring w-fit rounded font-semibold text-ink hover:text-primary">
                  {q.title}
                </Link>
                <span className="text-sm text-ink-soft xl:hidden">
                  {q.parent_type === "chapter" ? "Cả chương · " : "Bài · "}
                  {q.parent_title}
                </span>
              </div>
            ),
          },
          {
            key: "parent",
            header: "Gắn với",
            hideBelow: "xl",
            cell: (q) => (
              <span className="flex flex-col">
                <span className="text-ink">{q.parent_title}</span>
                <span className="text-xs text-ink-soft">{q.parent_type === "chapter" ? "Bài tập cuối chương" : "Bài tập của bài học"}</span>
              </span>
            ),
          },
          {
            key: "count",
            header: "Số câu",
            align: "right",
            cell: (q) =>
              (q.questions_count ?? 0) === 0 ? (
                <Badge tone="warning" size="sm" dot>
                  Chưa có câu
                </Badge>
              ) : (
                <span className="num">{q.questions_count}</span>
              ),
          },
          {
            key: "time",
            header: "Thời gian",
            hideBelow: "md",
            cell: (q) => (q.time_limit_minutes ? <span className="num">{q.time_limit_minutes} phút</span> : <span className="text-ink-soft">Không giới hạn</span>),
          },
          {
            key: "act",
            header: <span className="sr-only">Thao tác</span>,
            align: "right",
            cell: (q) => (
              <span className="inline-flex items-center gap-1">
                <Link href={quizHref(courseId, q.id)} className="focus-ring inline-flex h-9 items-center gap-1 rounded-control px-2 font-semibold text-primary hover:bg-primary-soft max-sm:h-11">
                  Soạn câu hỏi
                  <span className="sr-only">: {q.title}</span>
                  <IconArrowRight size={16} />
                </Link>
                <button
                  type="button"
                  aria-label={`Xoá bài tập ${q.title}`}
                  onClick={() => setDialog({ kind: "delete", quiz: q })}
                  className="focus-ring inline-flex size-9 items-center justify-center rounded-control text-danger hover:bg-danger-soft max-sm:size-11"
                >
                  <IconTrash size={16} />
                </button>
              </span>
            ),
          },
        ]}
      />

      {dialog?.kind === "create" || dialog?.kind === "edit" ? (
        <QuizSettingsDialog
          courseId={courseId}
          quiz={dialog.kind === "edit" ? dialog.quiz : undefined}
          onClose={() => setDialog(null)}
          onSaved={(saved, info) => {
            setDialog(null);
            commit([...(quizzes ?? []).filter((q) => q.id !== saved.id), saved].sort((a, b) => a.position - b.position || a.id - b.id));
            toast.show({ tone: "success", title: dialog.kind === "edit" ? "Đã lưu thông tin bài tập" : "Đã tạo bài tập" });
            if (info.timeIgnored) toast.show({ tone: "info", title: "Giới hạn thời gian đang tắt trên hệ thống nên chưa được áp dụng" });
          }}
        />
      ) : null}
      {dialog?.kind === "delete" ? (
        <ConfirmDialog
          open
          tone="danger"
          title={`Xoá bài tập “${dialog.quiz.title}”?`}
          description="Bài tập biến mất khỏi trang học của học sinh. Các câu hỏi và kết quả các lượt đã làm vẫn được giữ lại trong hệ thống. Hành động này không thể hoàn tác trên giao diện."
          confirmLabel="Xoá bài tập"
          loading={busy}
          loadingText="Đang xoá…"
          onClose={() => (busy ? undefined : setDialog(null))}
          onConfirm={() => void confirmDelete(dialog.quiz)}
        />
      ) : null}
    </div>
  );
}
