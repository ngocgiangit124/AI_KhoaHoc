"use client";

import { Badge, ButtonLink, CourseCover, EmptyState, IconBookOpen, IconPlay, Pagination, ProgressBar, Skeleton, Tabs, formatDate } from "@vitaminvui/ui/v2";
import { CourseImage } from "@/components/catalog/CourseImage";
import { AppLink } from "@/components/shell/AppLink";
import { fetchMyCourses } from "@/lib/my/api";
import { pickResume, progressText } from "@/lib/my/format";
import type { MyCourses, PendingEnrollment, RejectedEnrollment } from "@/lib/my/schemas";
import { routes } from "@/lib/routes";
import { MyCourseCard } from "./MyCourseCard";
import { MyNotice } from "./MyNotice";
import { PageSkeletonRegion, RequireUser } from "./RequireUser";
import { useMyLoad } from "./useMyLoad";

function ListSkeleton() {
  return (
    <PageSkeletonRegion label="Đang tải khóa học của bạn…">
      {Array.from({ length: 3 }).map((_, i) => (
        <div key={i} className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4 sm:flex-row">
          <Skeleton className="aspect-video w-full sm:w-56" />
          <div className="flex flex-1 flex-col gap-3">
            <Skeleton className="h-5 w-24" />
            <Skeleton className="h-6 w-3/4" />
            <Skeleton className="h-2 w-full" />
            <Skeleton className="h-11 w-36" />
          </div>
        </div>
      ))}
    </PageSkeletonRegion>
  );
}

function PendingList({ items }: { items: PendingEnrollment[] }) {
  if (items.length === 0) return <EmptyState size="inline" title="Không có yêu cầu nào đang chờ" headingLevel="h3" />;
  return (
    <ul className="flex flex-col gap-3">
      {items.map((p) => (
        <li key={p.enrollment_id} className="flex flex-col gap-2 rounded-card border border-line bg-surface p-4 sm:flex-row sm:items-center">
          <div className="flex-1">
            <AppLink href={routes.course(p.course.slug)} className="focus-ring rounded text-base font-semibold text-ink hover:text-primary">
              {p.course.title}
            </AppLink>
            <p className="text-sm text-ink-soft">Gửi yêu cầu ngày {formatDate(p.requested_at)}. Bạn sẽ nhận email khi được duyệt.</p>
          </div>
          <Badge tone="warning">Đang chờ duyệt</Badge>
        </li>
      ))}
    </ul>
  );
}

function RejectedList({ items }: { items: RejectedEnrollment[] }) {
  if (items.length === 0) return <EmptyState size="inline" title="Không có yêu cầu nào bị từ chối" headingLevel="h3" />;
  return (
    <ul className="flex flex-col gap-3">
      {items.map((p) => (
        <li key={p.enrollment_id} className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4">
          <div className="flex flex-wrap items-center gap-2">
            <span className="flex-1 text-base font-semibold text-ink">{p.course.title}</span>
            <Badge tone="danger">Không được duyệt</Badge>
          </div>
          {p.rejection_reason ? (
            <p className="rounded-control bg-sunken p-3 text-base text-ink">
              <span className="font-semibold">Lý do: </span>
              {p.rejection_reason}
            </p>
          ) : null}
          <div>
            <ButtonLink href={routes.course(p.course.slug)} variant="secondary">
              Xem khóa và đăng ký lại
            </ButtonLink>
          </div>
        </li>
      ))}
    </ul>
  );
}

function ResumeBanner({ item }: { item: NonNullable<ReturnType<typeof pickResume>> }) {
  const { course, progress } = item;
  return (
    <section aria-labelledby="hoc-tiep" className="mb-8 overflow-hidden rounded-sheet border border-primary/30 bg-primary-soft">
      <div className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:p-5">
        <div className="w-full overflow-hidden rounded-card sm:w-48">
          <CourseCover
            title={course.title}
            gradeLevel={course.grade_level}
            subjectSlug={course.slug}
            image={course.thumbnail_url ? <CourseImage url={course.thumbnail_url} sizes="(min-width: 640px) 192px, 100vw" /> : undefined}
          />
        </div>
        <div className="flex flex-1 flex-col gap-2">
          <h2 id="hoc-tiep" className="text-sm font-semibold text-primary">
            Học tiếp
          </h2>
          <p className="text-lg font-semibold text-ink">{course.title}</p>
          <ProgressBar value={progress.percent} label="Tiến độ" valueText={progressText(progress)} hasContent={progress.has_content} />
        </div>
        {item.resume_lesson_id ? (
          <ButtonLink href={routes.lesson(course.id, item.resume_lesson_id)} size="lg" leadingIcon={<IconPlay size={16} />}>
            Tiếp tục học
          </ButtonLink>
        ) : null}
      </div>
    </section>
  );
}

function Loaded({ data, page }: { data: MyCourses; page: number }) {
  const { pending, rejected } = data;
  if (data.data.length === 0 && pending.length === 0 && rejected.length === 0) {
    if (data.meta.total > 0 && page > 1) {
      return (
        <EmptyState
          icon={<IconBookOpen size={32} />}
          title="Trang này không có khóa học"
          description="Danh sách ngắn hơn bạn nghĩ."
          action={<ButtonLink href={routes.myCourses}>Về trang đầu</ButtonLink>}
        />
      );
    }
    return (
      <EmptyState
        icon={<IconBookOpen size={32} />}
        title="Bạn chưa có khóa học nào"
        description="Chọn một khóa phù hợp với lớp của bạn để bắt đầu. Nhiều khóa có bài học thử miễn phí."
        action={<ButtonLink href={routes.catalog}>Khám phá khóa học</ButtonLink>}
      />
    );
  }
  // "Học tiếp" chỉ ở trang đầu (API sắp học gần nhất lên đầu).
  const resume = page === 1 ? pickResume(data.data) : undefined;
  return (
    <>
      {resume ? <ResumeBanner item={resume} /> : null}
      <Tabs
        label="Nhóm khóa học"
        defaultTab={data.data.length === 0 && data.meta.total === 0 && pending.length > 0 ? "cho-duyet" : undefined}
        items={[
          {
            id: "dang-hoc",
            label: "Đang học",
            count: data.meta.total,
            content:
              data.data.length === 0 && data.meta.total > 0 && page > 1 ? (
                <EmptyState
                  size="inline"
                  title="Trang này không có khóa học"
                  headingLevel="h3"
                  action={<ButtonLink href={routes.myCourses}>Về trang đầu</ButtonLink>}
                />
              ) : data.data.length === 0 ? (
                <EmptyState
                  size="inline"
                  title="Chưa có khóa nào đang học"
                  headingLevel="h3"
                  action={<ButtonLink href={routes.catalog}>Khám phá khóa học</ButtonLink>}
                />
              ) : (
                <div className="flex flex-col gap-4">
                  {data.data.map((item) => (
                    <MyCourseCard key={item.enrollment.id} item={item} />
                  ))}
                  <Pagination currentPage={data.meta.current_page} lastPage={data.meta.last_page} hrefFor={(p) => (p > 1 ? `${routes.myCourses}?trang=${p}` : routes.myCourses)} className="mt-2" />
                </div>
              ),
          },
          { id: "cho-duyet", label: "Chờ duyệt", count: pending.length, content: <PendingList items={pending} /> },
          { id: "tu-choi", label: "Không được duyệt", count: rejected.length, content: <RejectedList items={rejected} /> },
        ]}
      />
    </>
  );
}

function Content({ page }: { page: number }) {
  const [state, retry] = useMyLoad(fetchMyCourses, page);
  if (state.status === "loading") return <ListSkeleton />;
  if (state.status === "failed") return <MyNotice kind={state.kind} onRetry={retry} what="list" />;
  return <Loaded data={state.data} page={page} />;
}

/** `/tai-khoan/khoa-hoc-cua-toi` (US-008 §2.1, GET /me/courses). */
export function MyCoursesScreen({ page }: { page: number }) {
  return (
    <div className="mx-auto w-full max-w-4xl px-4 pb-14 pt-6 sm:px-6">
      <h1 className="text-title font-extrabold tracking-heading text-ink md:text-title-lg">Khóa học của tôi</h1>
      <div className="mt-6">
        <RequireUser next={routes.myCourses} skeleton={<ListSkeleton />}>
          <Content key={page} page={page} />
        </RequireUser>
      </div>
    </div>
  );
}
