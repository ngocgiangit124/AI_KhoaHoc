"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState, type MutableRefObject } from "react";
import {
  Alert,
  Button,
  ConfirmDialog,
  EmptyState,
  IconChevronLeft,
  IconLock,
  IconSearch,
  IconTrash,
  IconX,
  LoadingRegion,
  Skeleton,
  useToast,
} from "@vitaminvui/ui/v2";
import {
  createChapter,
  createLesson,
  deleteChapter,
  deleteLesson,
  getCurriculum,
  renameChapter,
  saveCurriculumOrder,
} from "@/lib/curriculum/api";
import { curriculumError, isForbiddenError, isGone, isMismatch } from "@/lib/curriculum/errors";
import { canShiftLesson, countLessons, findLesson, shiftLesson, toOrderPayload } from "@/lib/curriculum/order";
import type { Chapter } from "@/lib/curriculum/types";
import { POLL_MAX_MS, isVideoPending, nextPollDelay } from "@/lib/curriculum/video";
import { ChapterTree } from "./ChapterTree";
import { LessonForm } from "./LessonForm";
import { NameDialog } from "./NameDialog";
import type { UploadManager } from "./useUploadManager";

type Dialog =
  | { kind: "add-chapter" }
  | { kind: "rename-chapter"; chapter: Chapter }
  | { kind: "delete-chapter"; chapter: Chapter }
  | { kind: "add-lesson"; chapter: Chapter }
  | { kind: "delete-lesson"; chapter: Chapter; lessonId: number }
  | { kind: "leave"; go: () => void };

export interface CurriculumPanelProps {
  courseId: number;
  manager: UploadManager;
  /** Tăng lên để tải lại cây ngay (tải video xong/huỷ). */
  refreshTick: number;
  /** Id các bài vừa tải xong: hỏi trạng thái dày hơn cho tới khi sẵn sàng/lỗi. */
  watchedLessonIds: readonly number[];
  onWatchedSettled: (ids: number[]) => void;
  /** Form bài đang có thay đổi chưa lưu (màn cha dùng để hỏi trước khi đổi tab). */
  dirtyRef: MutableRefObject<boolean>;
  selectedLessonId: number | null;
  lessonHref: (lessonId: number | null) => string;
  onSelect: (lessonId: number | null) => void;
  /** Thêm/xoá chương hoặc bài: màn cha tải lại số liệu của khóa (nút Xuất bản, số bài). */
  onStructureChanged: () => void;
}

/**
 * Tab "Chương & bài" (US-009 §2.3): cây kéo-thả (@dnd-kit) + form bài + video. Dữ liệu qua `GET chapters`, sắp xếp qua
 * `PUT curriculum/order` (lạc quan, hoàn lại khi lỗi; 422 CURRICULUM_MISMATCH → tải lại cây).
 */
export function CurriculumPanel(props: CurriculumPanelProps) {
  const { courseId, manager, refreshTick, watchedLessonIds, onWatchedSettled, dirtyRef, selectedLessonId, lessonHref, onSelect, onStructureChanged } = props;
  const toast = useToast();
  const [chapters, setChapters] = useState<Chapter[] | null>(null);
  const [loadError, setLoadError] = useState<unknown>(null);
  const [saving, setSaving] = useState(false);
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [formVersion, setFormVersion] = useState(0);
  const chaptersRef = useRef<Chapter[] | null>(null);
  const savingRef = useRef(false);
  // Tăng mỗi lần bắt đầu/kết thúc lưu thứ tự: GET gửi trước đó mà về muộn không được ghi đè.
  const seqRef = useRef(0);
  const [pollExpired, setPollExpired] = useState(false);
  const asideRef = useRef<HTMLElement>(null);
  const settledRef = useRef(onWatchedSettled);
  useEffect(() => {
    settledRef.current = onWatchedSettled;
  });

  const commit = useCallback((next: Chapter[] | null) => {
    chaptersRef.current = next;
    setChapters(next);
  }, []);

  const load = useCallback(
    async (signal?: AbortSignal) => {
      const seq = seqRef.current;
      try {
        const res = await getCurriculum(courseId, signal);
        if (signal?.aborted) return;
        // Đang lưu thứ tự, hoặc đã lưu xong sau khi GET này được gửi: bỏ kết quả cũ.
        if (!savingRef.current && seq === seqRef.current) commit(res.chapters);
        setLoadError(null);
      } catch (err) {
        if (signal?.aborted) return;
        if (chaptersRef.current === null || isForbiddenError(err)) setLoadError(err);
      }
    },
    [courseId, commit],
  );

  useEffect(() => {
    const controller = new AbortController();
    // eslint-disable-next-line react-hooks/set-state-in-effect -- load() chỉ setState sau khi await (bất đồng bộ), tải dữ liệu khi vào tab
    void load(controller.signal);
    return () => controller.abort();
  }, [load, refreshTick]);

  // Hỏi trạng thái video: bài đang xử lý (hoặc vừa tải xong mà server chưa chuyển sang xử lý). Không hỏi bài đang tải cục bộ.
  const pendingKey = (chapters ?? [])
    .flatMap((c) => c.lessons)
    .filter((l) => !manager.uploads[l.id] && (l.video_status === "processing" || (watchedLessonIds.includes(l.id) && isVideoPending(l.video_status))))
    .map((l) => l.id)
    .join(",");
  useEffect(() => {
    if (!pendingKey || pollExpired) return;
    const startedAt = Date.now();
    let timer: ReturnType<typeof setTimeout>;
    const controller = new AbortController();
    const tick = async () => {
      if (Date.now() - startedAt > POLL_MAX_MS) {
        setPollExpired(true);
        return;
      }
      if (!document.hidden) {
        await load(controller.signal);
        if (controller.signal.aborted) return;
        const still = new Set(
          (chaptersRef.current ?? []).flatMap((c) => c.lessons).filter((l) => isVideoPending(l.video_status)).map((l) => l.id),
        );
        const done = watchedLessonIds.filter((id) => !still.has(id));
        if (done.length > 0) settledRef.current(done);
      }
      timer = setTimeout(tick, nextPollDelay(Date.now() - startedAt));
    };
    timer = setTimeout(tick, nextPollDelay(0));
    return () => {
      controller.abort();
      clearTimeout(timer);
    };
  }, [pendingKey, load, watchedLessonIds, pollExpired]);

  const selected = findLesson(chapters ?? [], selectedLessonId);
  const selectedChapter = selected ? (chapters ?? []).find((c) => c.id === selected.chapter_id) : undefined;

  async function reorder(next: Chapter[]) {
    const prev = chaptersRef.current;
    setActionError(null);
    seqRef.current++;
    commit(next);
    savingRef.current = true;
    setSaving(true);
    try {
      const res = await saveCurriculumOrder(courseId, toOrderPayload(next));
      commit(res.chapters);
      toast.show({ tone: "success", title: "Đã lưu thứ tự" });
    } catch (err) {
      commit(prev);
      savingRef.current = false;
      if (isMismatch(err) || isGone(err)) await load();
      setActionError(curriculumError(err));
    } finally {
      savingRef.current = false;
      seqRef.current++;
      setSaving(false);
    }
  }

  const askLeave = (go: () => void) => setDialog({ kind: "leave", go });

  if (loadError && chapters === null) {
    if (isForbiddenError(loadError)) {
      return (
        <div data-testid="curriculum-forbidden" className="mx-auto max-w-xl py-8">
          <EmptyState icon={<IconLock size={32} />} title="Bạn không có quyền sửa nội dung khóa học này." description="Chỉ giáo viên được gán và quản trị viên mới sửa được chương và bài." />
        </div>
      );
    }
    if (isGone(loadError)) {
      return <EmptyState icon={<IconSearch size={32} />} title="Không tìm thấy khóa học" description="Khóa học có thể đã bị xoá." />;
    }
    return (
      <Alert
        tone="danger"
        title="Không tải được chương và bài"
        action={
          <Button size="sm" variant="secondary" className="max-sm:h-11" onClick={() => void load()}>
            Thử lại
          </Button>
        }
      >
        {curriculumError(loadError)}
      </Alert>
    );
  }
  if (chapters === null) {
    return (
      <LoadingRegion className="flex flex-col gap-3">
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-40 w-full" />
        <Skeleton className="h-24 w-full" />
      </LoadingRegion>
    );
  }

  const lessonTotal = countLessons(chapters);

  return (
    <div>
      {chapters.length === 0 ? (
        <Alert
          tone="info"
          title="Khóa học chưa có chương nào"
          action={
            <Button size="sm" className="max-sm:h-11" onClick={() => setDialog({ kind: "add-chapter" })}>
              Thêm chương đầu tiên
            </Button>
          }
        >
          Thêm chương và ít nhất 1 bài học để có thể xuất bản.
        </Alert>
      ) : (
        <div className="grid gap-6 xl:grid-cols-[1fr_400px]">
          <div className="flex min-w-0 flex-col gap-3">
            <p className="text-sm text-ink-soft">
              Kéo biểu tượng ⠿ để sắp xếp chương và bài (kéo bài sang chương khác được). Dùng bàn phím: chọn bài rồi dùng nút “Lên/Xuống” trong khung sửa. {lessonTotal} bài trong {chapters.length} chương.
            </p>
            {pollExpired && pendingKey ? (
              <Alert
                tone="warning"
                title="Video chưa cập nhật trạng thái"
                action={
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => {
                      setPollExpired(false);
                      void load();
                    }}
                  >
                    Kiểm tra lại
                  </Button>
                }
              >
                Đã chờ hơn 10 phút mà video vẫn chưa xử lý xong hoặc tải lên chưa hoàn tất. Hãy kiểm tra lại sau, hoặc chọn lại tệp.
              </Alert>
            ) : null}
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
            <div aria-busy={saving}>
              <ChapterTree
                chapters={chapters}
                selectedId={selected?.id ?? null}
                uploads={manager.uploads}
                locked={saving}
                lessonHref={(id) => lessonHref(id)}
                onSelectLesson={(id, e) => {
                  if (dirtyRef.current && id !== selectedLessonId) {
                    e.preventDefault();
                    askLeave(() => onSelect(id));
                  } else if (window.innerWidth < 1280) {
                    setTimeout(() => asideRef.current?.scrollIntoView({ block: "start", behavior: "smooth" }), 50);
                  }
                }}
                onReorder={(next) => void reorder(next)}
                onAddChapter={() => setDialog({ kind: "add-chapter" })}
                onRenameChapter={(chapter) => setDialog({ kind: "rename-chapter", chapter })}
                onDeleteChapter={(chapter) => setDialog({ kind: "delete-chapter", chapter })}
                onAddLesson={(chapter) => setDialog({ kind: "add-lesson", chapter })}
              />
            </div>
          </div>
          <aside ref={asideRef} aria-labelledby="sua-bai" className="scroll-mt-4 xl:sticky xl:top-6 xl:self-start">
            {selected ? (
              <div className="flex flex-col gap-4 rounded-card border border-line bg-surface p-5 shadow-raised">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="text-xs font-medium text-ink-soft">Sửa bài học{selectedChapter ? ` · ${selectedChapter.title}` : ""}</p>
                    <h2 id="sua-bai" className="break-words text-base font-semibold text-ink">
                      {selected.title}
                    </h2>
                  </div>
                  <Link
                    href={lessonHref(null)}
                    scroll={false}
                    aria-label="Đóng khung sửa bài"
                    onClick={(e) => {
                      if (dirtyRef.current) {
                        e.preventDefault();
                        askLeave(() => onSelect(null));
                      }
                    }}
                    className="focus-ring relative inline-flex size-9 shrink-0 items-center justify-center rounded-control text-ink before:absolute before:-inset-1 before:content-[''] hover:bg-sunken"
                  >
                    <IconX size={18} />
                  </Link>
                </div>
                <div className="flex flex-wrap gap-2">
                  <Button
                    variant="secondary"
                    size="sm"
                    className="max-sm:h-11"
                    disabled={saving || !canShiftLesson(chapters, selected.id, -1)}
                    leadingIcon={<IconChevronLeft size={16} className="rotate-90" />}
                    onClick={() => {
                      const next = shiftLesson(chapters, selected.id, -1);
                      if (next) void reorder(next);
                    }}
                  >
                    Lên
                  </Button>
                  <Button
                    variant="secondary"
                    size="sm"
                    className="max-sm:h-11"
                    disabled={saving || !canShiftLesson(chapters, selected.id, 1)}
                    leadingIcon={<IconChevronLeft size={16} className="-rotate-90" />}
                    onClick={() => {
                      const next = shiftLesson(chapters, selected.id, 1);
                      if (next) void reorder(next);
                    }}
                  >
                    Xuống
                  </Button>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="ml-auto text-danger hover:bg-danger-soft max-sm:h-11"
                    leadingIcon={<IconTrash size={16} />}
                    onClick={() => selectedChapter && setDialog({ kind: "delete-lesson", chapter: selectedChapter, lessonId: selected.id })}
                  >
                    Xoá bài
                  </Button>
                </div>
                <LessonForm
                  key={`${selected.id}:${formVersion}`}
                  courseId={courseId}
                  lesson={selected}
                  manager={manager}
                  onDirtyChange={(d) => {
                    dirtyRef.current = d;
                  }}
                  onSaved={(saved) => {
                    commit((chaptersRef.current ?? []).map((c) => ({ ...c, lessons: c.lessons.map((l) => (l.id === saved.id ? saved : l)) })));
                    setFormVersion((n) => n + 1);
                    toast.show({ tone: "success", title: "Đã lưu bài học" });
                  }}
                  onGone={() => {
                    setActionError("Bài học không còn tồn tại.");
                    onSelect(null);
                    void load();
                  }}
                  onCancel={() => {
                    setFormVersion((n) => n + 1);
                    dirtyRef.current = false;
                  }}
                />
              </div>
            ) : (
              <div className="flex flex-col gap-2 rounded-card border border-dashed border-line-strong p-5 text-sm text-ink-soft">
                <h2 id="sua-bai" className="text-base font-semibold text-ink">
                  Chọn một bài để sửa
                </h2>
                <p>Bấm vào bài trong danh sách bên cạnh để sửa tên, nguồn video và tải video lên.</p>
              </div>
            )}
          </aside>
        </div>
      )}

      {dialog?.kind === "add-chapter" ? (
        <NameDialog
          title="Thêm chương"
          label="Tên chương"
          submitLabel="Thêm chương"
          onClose={() => setDialog(null)}
          onSubmit={async (title) => {
            const chapter = await createChapter(courseId, title);
            commit([...(chaptersRef.current ?? []), { ...chapter, lessons: chapter.lessons ?? [] }]);
            setDialog(null);
            onStructureChanged();
          }}
        />
      ) : null}
      {dialog?.kind === "rename-chapter" ? (
        <NameDialog
          title="Sửa tên chương"
          label="Tên chương"
          submitLabel="Lưu tên"
          initial={dialog.chapter.title}
          onClose={() => setDialog(null)}
          onSubmit={async (title) => {
            const id = dialog.chapter.id;
            try {
              const updated = await renameChapter(courseId, id, title);
              commit((chaptersRef.current ?? []).map((c) => (c.id === id ? { ...c, title: updated.title } : c)));
              setDialog(null);
            } catch (err) {
              if (isGone(err)) {
                setDialog(null);
                setActionError("Chương không còn tồn tại.");
                void load();
                return;
              }
              throw err;
            }
          }}
        />
      ) : null}
      {dialog?.kind === "add-lesson" ? (
        <NameDialog
          title={`Thêm bài vào “${dialog.chapter.title}”`}
          label="Tên bài học"
          submitLabel="Thêm bài"
          onClose={() => setDialog(null)}
          onSubmit={async (title) => {
            const chapterId = dialog.chapter.id;
            try {
              const lesson = await createLesson(courseId, chapterId, { title, is_preview: false, video_source: "none" });
              commit((chaptersRef.current ?? []).map((c) => (c.id === chapterId ? { ...c, lessons: [...c.lessons, lesson] } : c)));
              setDialog(null);
              onStructureChanged();
              onSelect(lesson.id);
            } catch (err) {
              if (isGone(err)) {
                setDialog(null);
                setActionError("Chương không còn tồn tại.");
                void load();
                return;
              }
              throw err;
            }
          }}
        />
      ) : null}

      {dialog?.kind === "delete-chapter" ? (
        <ConfirmDialog
          open
          tone="danger"
          title={`Xoá “${dialog.chapter.title}”?`}
          description={
            dialog.chapter.lessons.length > 0
              ? `Chương có ${dialog.chapter.lessons.length} bài học, tất cả sẽ bị xoá cùng chương. Hành động này không thể hoàn tác.`
              : "Chương này chưa có bài học. Hành động này không thể hoàn tác."
          }
          confirmLabel="Xoá chương"
          loading={busy}
          loadingText="Đang xoá…"
          onClose={() => setDialog(null)}
          onConfirm={async () => {
            const target = dialog.chapter;
            setBusy(true);
            try {
              await deleteChapter(courseId, target.id);
              for (const l of target.lessons) manager.cancel(l.id);
              commit((chaptersRef.current ?? []).filter((c) => c.id !== target.id));
              if (target.lessons.some((l) => l.id === selectedLessonId)) onSelect(null);
              toast.show({ tone: "success", title: "Đã xoá chương" });
              onStructureChanged();
            } catch (err) {
              setActionError(curriculumError(err));
              if (isGone(err)) void load();
            } finally {
              setBusy(false);
              setDialog(null);
            }
          }}
        />
      ) : null}
      {dialog?.kind === "delete-lesson" ? (
        <ConfirmDialog
          open
          tone="danger"
          title="Xoá bài học này?"
          description="Bài học và video đính kèm sẽ bị xoá. Hành động này không thể hoàn tác."
          confirmLabel="Xoá bài"
          loading={busy}
          loadingText="Đang xoá…"
          onClose={() => setDialog(null)}
          onConfirm={async () => {
            const { chapter, lessonId } = dialog;
            setBusy(true);
            try {
              await deleteLesson(courseId, chapter.id, lessonId);
              manager.cancel(lessonId);
              commit((chaptersRef.current ?? []).map((c) => ({ ...c, lessons: c.lessons.filter((l) => l.id !== lessonId) })));
              dirtyRef.current = false;
              onSelect(null);
              toast.show({ tone: "success", title: "Đã xoá bài học" });
              onStructureChanged();
            } catch (err) {
              setActionError(curriculumError(err));
              if (isGone(err)) void load();
            } finally {
              setBusy(false);
              setDialog(null);
            }
          }}
        />
      ) : null}
      {dialog?.kind === "leave" ? (
        <ConfirmDialog
          open
          title="Còn thay đổi chưa lưu"
          description="Thông tin bài học bạn vừa sửa chưa được lưu. Chuyển sang bài khác sẽ bỏ các thay đổi này."
          confirmLabel="Bỏ thay đổi"
          cancelLabel="Ở lại để lưu"
          onClose={() => setDialog(null)}
          onConfirm={() => {
            const go = dialog.go;
            dirtyRef.current = false;
            setDialog(null);
            go();
          }}
        />
      ) : null}
    </div>
  );
}
