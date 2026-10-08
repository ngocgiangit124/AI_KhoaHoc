"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import {
  Alert,
  Badge,
  Breadcrumb,
  Button,
  ButtonLink,
  ConfirmDialog,
  EmptyState,
  IconLock,
  IconSearch,
  LoadingRegion,
  Skeleton,
  UiLink,
  formatCount,
  formatDateTime,
  useToast,
} from "@vitaminvui/ui/v2";
import { getCourse } from "@/lib/courses/api";
import { courseActionError, isForbidden, isNotFound } from "@/lib/courses/errors";
import { isCourseStaff } from "@/lib/courses/permissions";
import { COURSES_PATH } from "@/lib/courses/query";
import type { CourseDetail } from "@/lib/courses/types";
import { useSession } from "@/lib/auth/SessionProvider";
import { CurriculumPanel } from "@/components/curriculum/CurriculumPanel";
import { useUnsavedChangesGuard } from "@/lib/unsaved/useUnsavedChangesGuard";
import { QuizListPanel } from "@/components/quiz/QuizListPanel";
import { useUploadManager } from "@/components/curriculum/useUploadManager";
import { CourseForm } from "./CourseForm";
import { ManualOrderField } from "./ManualOrderField";
import { CourseStatusBadge } from "./StatusBadge";
import { TeachersCard } from "./TeachersCard";
import { useCourseActions } from "./useCourseActions";

type Result = { key: number; course: CourseDetail | null; error: unknown };

/**
 * `/quan-tri/khoa-hoc/{id}/sua` — tab "Thông tin chung", "Chương & bài" (`?tab=chuong-bai&bai=ID`, FA4) và "Bài tập"
 * (`?tab=bai-tap`, FA5) theo design v2. Trường ẩn/hiện theo `abilities` của API (quyền thật do Policy).
 */
export function CourseEditScreen({ id }: { id: number }) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const toast = useToast();
  const { state } = useSession();
  const [reloadKey, setReloadKey] = useState(0);
  const [result, setResult] = useState<Result | null>(null);
  // Tăng sau mỗi lần lưu để dựng lại form từ dữ liệu server (ảnh đã chọn, giá trị chuẩn hoá).
  const [formVersion, setFormVersion] = useState(0);
  const [formDirty, setFormDirty] = useState(false);
  // Xuất bản/ngừng bán khi form còn thay đổi chưa lưu → hỏi xác nhận (R3).
  const [pendingAction, setPendingAction] = useState<(() => void) | null>(null);
  const ready = state.kind === "staff";
  const tabParam = searchParams.get("tab");
  const tab = tabParam === "chuong-bai" || tabParam === "bai-tap" ? tabParam : "thong-tin";
  const [quizCount, setQuizCount] = useState<number | null>(null);
  const lessonParam = searchParams.get("bai");
  const selectedLessonId = lessonParam && /^[1-9]\d{0,9}$/.test(lessonParam) ? Number(lessonParam) : null;
  // Tải video (FA4): quản lý ở đây để đổi bài/đổi tab không làm mất lượt tải đang chạy.
  const [refreshTick, setRefreshTick] = useState(0);
  const [watched, setWatched] = useState<number[]>([]);
  // Form bài (tab Chương & bài) còn thay đổi chưa lưu: hỏi trước khi sang tab Thông tin chung.
  // Liên kết ngoài khung nội dung (sidebar, header) cũng phải hỏi khi form bài chưa lưu; liên kết trong khung đã có cơ chế riêng.
  const contentRef = useRef<HTMLDivElement>(null);
  const { dirtyRef: lessonDirtyRef, dialog: unsavedDialog } = useUnsavedChangesGuard({
    ignoreInside: contentRef,
    description: "Thông tin bài học bạn vừa sửa chưa được lưu. Rời trang này sẽ bỏ các thay đổi đó.",
  });
  const [leaveTo, setLeaveTo] = useState<string | null>(null);
  const onUploadFinished = useCallback((lessonId: number, outcome: "uploaded" | "stopped") => {
    if (outcome === "uploaded") setWatched((prev) => (prev.includes(lessonId) ? prev : [...prev, lessonId]));
    setRefreshTick((n) => n + 1);
  }, []);
  const uploads = useUploadManager(id, onUploadFinished);
  const onWatchedSettled = useCallback((ids: number[]) => setWatched((prev) => prev.filter((x) => !ids.includes(x))), []);
  const curriculumHref = useCallback(
    (lessonId: number | null) => `${pathname}?tab=chuong-bai${lessonId ? `&bai=${lessonId}` : ""}`,
    [pathname],
  );
  const selectLesson = useCallback((lessonId: number | null) => router.replace(curriculumHref(lessonId), { scroll: false }), [router, curriculumHref]);

  useEffect(() => {
    if (!ready) return;
    const controller = new AbortController();
    getCourse(id, controller.signal)
      .then((course) => setResult({ key: reloadKey, course, error: null }))
      .catch((error: unknown) => {
        if (controller.signal.aborted) return;
        setResult((prev) => ({ key: reloadKey, course: isNotFound(error) || isForbidden(error) ? null : (prev?.course ?? null), error }));
      });
    return () => controller.abort();
  }, [id, reloadKey, ready]);

  const reload = useCallback(() => setReloadKey((n) => n + 1), []);
  const onDone = useCallback(
    (kind: "publish" | "unpublish" | "delete") => {
      if (kind === "delete") router.push(COURSES_PATH);
      else reload();
    },
    [router, reload],
  );
  const actions = useCourseActions({ onDone, onStale: reload });
  const replaceCourse = useCallback((course: CourseDetail) => setResult({ key: reloadKey, course, error: null }), [reloadKey]);
  const markGone = useCallback(() => setResult({ key: reloadKey, course: null, error: new Error("gone") }), [reloadKey]);

  if (state.kind !== "staff") return null;
  const isStaff = isCourseStaff(state.user);
  const course = result?.course ?? null;
  const loading = result === null || (result.key !== reloadKey && !course);
  const error = result?.error;
  const listLabel = isStaff ? "Khóa học" : "Khóa học của tôi";

  const back = (
    <ButtonLink href={COURSES_PATH} variant="secondary" size="sm">
      Về danh sách khóa học
    </ButtonLink>
  );

  if (!course && !loading && error) {
    if (isForbidden(error)) {
      return (
        <div data-testid="course-forbidden" className="mx-auto max-w-xl py-8">
          <EmptyState
            headingLevel="h1"
            icon={<IconLock size={32} />}
            title="Bạn không có quyền truy cập khóa học này."
            description="Chỉ giáo viên được gán và quản trị viên mới xem được khóa học."
            action={back}
          />
        </div>
      );
    }
    if (isNotFound(error) || (error instanceof Error && error.message === "gone")) {
      return (
        <div data-testid="course-not-found" className="mx-auto max-w-xl py-8">
          <EmptyState headingLevel="h1" icon={<IconSearch size={32} />} title="Không tìm thấy khóa học" description="Khóa học có thể đã bị xoá." action={back} />
        </div>
      );
    }
    return (
      <div className="flex flex-col gap-4">
        <Breadcrumb items={[{ label: listLabel, href: COURSES_PATH }, { label: "Sửa khóa học" }]} />
        <Alert
          tone="danger"
          title="Không tải được khóa học"
          action={
            <Button size="sm" variant="secondary" onClick={reload}>
              Thử lại
            </Button>
          }
        >
          {courseActionError(error)}
        </Alert>
      </div>
    );
  }
  if (!course) {
    return (
      <LoadingRegion className="flex flex-col gap-4">
        <Skeleton className="h-5 w-48" />
        <Skeleton className="h-9 w-80 max-w-full" />
        <Skeleton className="h-96 w-full" />
      </LoadingRegion>
    );
  }

  const a = course.abilities;
  const target = { id: course.id, title: course.title, status: course.status };
  const busy = actions.busyIds.has(course.id);
  const guard = (fn: () => void) => (formDirty ? setPendingAction(() => fn) : fn());
  const hasStudents = course.enrollments_count > 0;

  return (
    <div className="flex flex-col" ref={contentRef}>
      {unsavedDialog}
      <Breadcrumb items={[{ label: listLabel, href: COURSES_PATH }, { label: course.title }]} />
      <div className="mt-3 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="break-words text-title font-extrabold tracking-heading text-ink">{course.title}</h1>
            <CourseStatusBadge status={course.status} />
          </div>
          <p className="mt-1 text-sm text-ink-soft">
            {course.updated_at ? `Cập nhật ${formatDateTime(course.updated_at)} · ` : ""}
            {course.status === "published" ? `Trang công khai: /khoa-hoc/${course.slug}` : "Chưa có trang công khai (khóa chưa xuất bản)"}
          </p>
        </div>
        {a.publish ? (
          <div className="flex shrink-0 gap-2">
            {course.status === "published" ? (
              <Button variant="secondary" size="sm" className="max-sm:h-11" disabled={busy} onClick={() => guard(() => actions.askUnpublish(target))}>
                Ngừng bán
              </Button>
            ) : (
              <Button size="sm" className="max-sm:h-11" loading={busy} onClick={() => guard(() => actions.publish(target))}>
                {course.status === "unpublished" ? "Xuất bản lại" : "Xuất bản"}
              </Button>
            )}
          </div>
        ) : null}
      </div>

      <nav aria-label="Phần của khóa học" className="mt-5 flex gap-6 overflow-x-auto border-b border-line">
        <UiLink
          href={`${COURSES_PATH}/${course.id}/sua`}
          aria-current={tab === "thong-tin" ? "page" : undefined}
          onClick={(e) => {
            if ((tab === "chuong-bai" || tab === "bai-tap") && lessonDirtyRef.current) {
              e.preventDefault();
              setLeaveTo(`${COURSES_PATH}/${id}/sua`);
            }
          }}
          className={`focus-ring relative inline-flex min-h-11 items-center gap-2 whitespace-nowrap px-1 text-base font-semibold ${tab === "thong-tin" ? "text-primary" : "text-ink-soft hover:text-ink"}`}
        >
          Thông tin chung
          {tab === "thong-tin" ? <span aria-hidden="true" className="absolute inset-x-0 -bottom-px h-0.75 rounded-full bg-primary" /> : null}
        </UiLink>
        <UiLink
          href={`${COURSES_PATH}/${course.id}/sua?tab=chuong-bai`}
          aria-current={tab === "chuong-bai" ? "page" : undefined}
          className={`focus-ring relative inline-flex min-h-11 items-center gap-2 whitespace-nowrap px-1 text-base font-semibold ${tab === "chuong-bai" ? "text-primary" : "text-ink-soft hover:text-ink"}`}
        >
          Chương &amp; bài
          {course.lessons_count !== undefined ? <span className="num rounded-full bg-sunken px-2 text-sm text-ink-soft">{course.lessons_count}</span> : null}
          {tab === "chuong-bai" ? <span aria-hidden="true" className="absolute inset-x-0 -bottom-px h-0.75 rounded-full bg-primary" /> : null}
        </UiLink>
        <UiLink
          href={`${COURSES_PATH}/${course.id}/sua?tab=bai-tap`}
          aria-current={tab === "bai-tap" ? "page" : undefined}
          onClick={(e) => {
            if (tab === "chuong-bai" && lessonDirtyRef.current) {
              e.preventDefault();
              setLeaveTo(`${COURSES_PATH}/${id}/sua?tab=bai-tap`);
            }
          }}
          className={`focus-ring relative inline-flex min-h-11 items-center gap-2 whitespace-nowrap px-1 text-base font-semibold ${tab === "bai-tap" ? "text-primary" : "text-ink-soft hover:text-ink"}`}
        >
          Bài tập
          {quizCount !== null ? <span className="num rounded-full bg-sunken px-2 text-sm text-ink-soft">{quizCount}</span> : null}
          {tab === "bai-tap" ? <span aria-hidden="true" className="absolute inset-x-0 -bottom-px h-0.75 rounded-full bg-primary" /> : null}
        </UiLink>
      </nav>

      {tab === "chuong-bai" ? (
        <div className="mt-5">
          <CurriculumPanel
            courseId={course.id}
            manager={uploads}
            refreshTick={refreshTick}
            dirtyRef={lessonDirtyRef}
            watchedLessonIds={watched}
            onWatchedSettled={onWatchedSettled}
            selectedLessonId={selectedLessonId}
            lessonHref={curriculumHref}
            onSelect={selectLesson}
            onStructureChanged={reload}
          />
        </div>
      ) : null}

      {tab === "bai-tap" ? (
        <div className="mt-5">
          <QuizListPanel courseId={course.id} onCountChange={setQuizCount} />
        </div>
      ) : null}

      {/* Giữ form thông tin mounted khi đang ở tab khác để không mất phần đang sửa. */}
      <div className="mt-5" hidden={tab !== "thong-tin"}>
        <CourseForm
          key={course.id}
          version={formVersion}
          onDirtyChange={setFormDirty}
          mode="edit"
          course={course}
          isStaff={isStaff}
          onSaved={(saved) => {
            toast.show({ tone: "success", title: "Đã lưu thay đổi" });
            if (saved) {
              replaceCourse(saved);
              setFormVersion((n) => n + 1);
            }
          }}
          onGone={markGone}
          aside={
            <>
              {a.manage_teachers ? (
                <TeachersCard key={`t-${course.teachers.map((t) => t.id).join("-")}`} course={course} onSaved={replaceCourse} onGone={markGone} />
              ) : (
                <section aria-labelledby="giao-vien" className="rounded-card border border-line bg-surface p-5">
                  <h2 id="giao-vien" className="text-base font-semibold text-ink">
                    Giáo viên phụ trách
                  </h2>
                  <p className="mt-2 text-sm text-ink">{course.teachers.map((t) => t.name).join(", ") || "—"}</p>
                  <p className="mt-1 text-sm text-ink-soft">Admin/Quản lý trang phân công thêm giáo viên.</p>
                </section>
              )}
              <section aria-labelledby="hien-thi" className="flex flex-col gap-3 rounded-card border border-line bg-surface p-5 text-sm">
                <h2 id="hien-thi" className="text-base font-semibold text-ink">
                  Hiển thị
                </h2>
                <p className="flex items-center justify-between gap-2 text-ink-soft">
                  Học sinh đã đăng ký <span className="num font-semibold text-ink">{formatCount(course.enrollments_count)}</span>
                </p>
                {isStaff ? (
                  <ManualOrderField key={`o-${course.manual_order ?? "none"}`} courseId={course.id} value={course.manual_order} onSaved={reload} onGone={markGone} />
                ) : null}
                {!a.publish ? <Badge tone="info">Khóa học sẽ được Admin xem xét và xuất bản.</Badge> : null}
              </section>
            </>
          }
        />

        {a.delete ? (
          <section aria-labelledby="nguy-hiem" className="mt-8 rounded-card border border-danger/40 bg-surface p-5">
            <h2 id="nguy-hiem" className="text-base font-semibold text-danger">
              Xoá khóa học
            </h2>
            {hasStudents ? (
              <>
                <p className="mt-1 text-sm text-ink">
                  Không thể xoá vì đã có {formatCount(course.enrollments_count)} học sinh đăng ký. Hãy chuyển sang <strong>Ngừng bán</strong>: khóa ẩn khỏi danh mục, học sinh đã mua vẫn học được.
                </p>
                <Button variant="danger" size="sm" disabled className="mt-3 max-sm:h-11">
                  Xoá khóa học
                </Button>
              </>
            ) : (
              <>
                <p className="mt-1 text-sm text-ink">Xoá khóa học cùng toàn bộ chương và bài. Hành động này không thể hoàn tác.</p>
                <Button variant="danger" size="sm" className="mt-3 max-sm:h-11" disabled={busy} onClick={() => actions.askDelete(target)}>
                  Xoá khóa học
                </Button>
              </>
            )}
          </section>
        ) : null}

        <p className="mt-6 text-sm text-ink-soft">
          Hiện có {course.chapters_count ?? 0} chương, {course.lessons_count ?? 0} bài học. Soạn chương và bài ở tab “Chương &amp; bài”, bài tập trắc nghiệm ở tab “Bài tập”.
        </p>
      </div>

      {leaveTo ? (
        <ConfirmDialog
          open
          title="Còn thay đổi chưa lưu"
          description="Thông tin bài học bạn vừa sửa chưa được lưu. Chuyển tab sẽ bỏ các thay đổi này."
          confirmLabel="Bỏ thay đổi"
          cancelLabel="Ở lại để lưu"
          onClose={() => setLeaveTo(null)}
          onConfirm={() => {
            lessonDirtyRef.current = false;
            const to = leaveTo;
            setLeaveTo(null);
            router.push(to);
          }}
        />
      ) : null}
      {actions.dialogs}
      {pendingAction ? (
        <ConfirmDialog
          open
          title="Còn thay đổi chưa lưu"
          description="Thông tin bạn vừa sửa chưa được lưu. Thao tác này chỉ áp dụng cho bản đã lưu, thay đổi chưa lưu vẫn còn trong form."
          confirmLabel="Tiếp tục"
          cancelLabel="Quay lại để lưu"
          onClose={() => setPendingAction(null)}
          onConfirm={() => {
            const fn = pendingAction;
            setPendingAction(null);
            fn();
          }}
        />
      ) : null}
    </div>
  );
}
