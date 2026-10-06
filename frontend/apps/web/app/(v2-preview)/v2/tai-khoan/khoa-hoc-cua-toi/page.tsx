import Link from "next/link";
import {
  Alert,
  Badge,
  ButtonLink,
  CourseCover,
  EmptyState,
  IconBookOpen,
  IconPlay,
  IconRotateCcw,
  LoadingRegion,
  ProgressBar,
  Skeleton,
  Tabs,
  formatDate,
} from "@vitaminvui/ui/v2";
import { MyCourseCard } from "@/components/v2/my/MyCourseCard";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { StudentShell } from "@/components/v2/StudentShell";
import { myCourses } from "@/lib/mock/v2/my-courses";
import { one, routes } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

const STATES: Array<{ key?: string; label: string }> = [
  { label: "Có khóa học" },
  { key: "dang-tai", label: "Đang tải" },
  { key: "rong", label: "Chưa có khóa nào" },
  { key: "loi", label: "Lỗi tải" },
];

/** Khóa học của tôi (US-008, GET /me/courses). */
export default async function MyCoursesPreview({ searchParams }: PageProps<"/v2/tai-khoan/khoa-hoc-cua-toi">) {
  const sp = await searchParams;
  const state = one(sp["trang-thai"]);
  const data = state === "rong" ? { ...myCourses, data: [], pending: [], rejected: [] } : myCourses;
  const resume = data.data.find((d) => d.enrollment.last_accessed_at && !d.progress.is_completed);

  let body: React.ReactNode;
  if (state === "dang-tai") {
    body = (
      <LoadingRegion label="Đang tải khóa học của bạn…" className="flex flex-col gap-4">
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
      </LoadingRegion>
    );
  } else if (state === "loi") {
    body = (
      <Alert
        tone="danger"
        title="Không tải được danh sách khóa học của bạn"
        action={
          <ButtonLink href={routes.myCourses} variant="secondary" size="sm" leadingIcon={<IconRotateCcw size={16} />}>
            Thử lại
          </ButtonLink>
        }
      >
        Kiểm tra kết nối mạng rồi thử lại.
      </Alert>
    );
  } else if (data.data.length === 0 && data.pending.length === 0) {
    body = (
      <EmptyState
        icon={<IconBookOpen size={32} />}
        title="Bạn chưa có khóa học nào"
        description="Chọn một khóa phù hợp với lớp của bạn để bắt đầu. Nhiều khóa có bài học thử miễn phí."
        action={<ButtonLink href={routes.catalog}>Khám phá khóa học</ButtonLink>}
      />
    );
  } else {
    body = (
      <>
        {resume ? (
          <section aria-labelledby="hoc-tiep" className="mb-8 overflow-hidden rounded-sheet border border-primary/30 bg-primary-soft">
            <div className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:p-5">
              <div className="w-full overflow-hidden rounded-card sm:w-48">
                <CourseCover title={resume.course.title} gradeLevel={resume.course.grade_level} subjectSlug={resume.course.slug} />
              </div>
              <div className="flex flex-1 flex-col gap-2">
                <h2 id="hoc-tiep" className="text-sm font-semibold text-primary">
                  Học tiếp
                </h2>
                <p className="text-lg font-semibold text-ink">{resume.course.title}</p>
                <ProgressBar value={resume.progress.percent} label="Tiến độ" valueText={`${resume.progress.completed_lessons}/${resume.progress.total_lessons} bài · ${resume.progress.percent}%`} />
              </div>
              {resume.resume_lesson_id ? (
                <ButtonLink href={routes.lesson(resume.course.id, resume.resume_lesson_id)} size="lg" leadingIcon={<IconPlay size={16} />}>
                  Tiếp tục học
                </ButtonLink>
              ) : null}
            </div>
          </section>
        ) : null}

        <Tabs
          label="Nhóm khóa học"
          items={[
            {
              id: "dang-hoc",
              label: "Đang học",
              count: data.meta.total,
              content: (
                <div className="flex flex-col gap-4">
                  {data.data.map((item) => (
                    <MyCourseCard key={item.enrollment.id} item={item} />
                  ))}
                </div>
              ),
            },
            {
              id: "cho-duyet",
              label: "Chờ duyệt",
              count: data.pending.length,
              content: data.pending.length ? (
                <ul className="flex flex-col gap-3">
                  {data.pending.map((p) => (
                    <li key={p.enrollment_id} className="flex flex-col gap-2 rounded-card border border-line bg-surface p-4 sm:flex-row sm:items-center">
                      <div className="flex-1">
                        <Link href={routes.course(p.course.slug)} className="focus-ring rounded text-base font-semibold text-ink hover:text-primary">
                          {p.course.title}
                        </Link>
                        <p className="text-sm text-ink-soft">Gửi yêu cầu ngày {formatDate(p.requested_at)}. Bạn sẽ nhận email khi được duyệt.</p>
                      </div>
                      <Badge tone="warning">Đang chờ duyệt</Badge>
                    </li>
                  ))}
                </ul>
              ) : (
                <EmptyState size="inline" title="Không có yêu cầu nào đang chờ" headingLevel="h3" />
              ),
            },
            {
              id: "tu-choi",
              label: "Không được duyệt",
              count: data.rejected.length,
              content: data.rejected.length ? (
                <ul className="flex flex-col gap-3">
                  {data.rejected.map((p) => (
                    <li key={p.enrollment_id} className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="flex-1 text-base font-semibold text-ink">{p.course.title}</span>
                        <Badge tone="danger">Không được duyệt</Badge>
                      </div>
                      {p.rejection_reason ? (
                        <p className="rounded-control bg-sunken p-3 text-sm text-ink">
                          <span className="font-semibold">Lý do: </span>
                          {p.rejection_reason}
                        </p>
                      ) : null}
                      <div>
                        <ButtonLink href={routes.course(p.course.slug)} variant="secondary" size="sm">
                          Xem khóa và đăng ký lại
                        </ButtonLink>
                      </div>
                    </li>
                  ))}
                </ul>
              ) : (
                <EmptyState size="inline" title="Không có yêu cầu nào bị từ chối" headingLevel="h3" />
              ),
            },
          ]}
        />
      </>
    );
  }

  return (
    <StudentShell current="my-courses" loggedIn preview={<PreviewBar variants={STATES.map((s) => ({ label: s.label, href: s.key ? `${routes.myCourses}?trang-thai=${s.key}` : routes.myCourses, current: s.key === state }))} />}>
      <div className="mx-auto max-w-4xl px-4 pb-14 pt-6 sm:px-6">
        <h1 className="text-title font-extrabold tracking-heading text-ink md:text-title-lg">Khóa học của tôi</h1>
        <div className="mt-6">{body}</div>
      </div>
    </StudentShell>
  );
}
