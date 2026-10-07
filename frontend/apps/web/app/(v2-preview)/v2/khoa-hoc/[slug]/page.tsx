import { notFound } from "next/navigation";
import {
  Avatar,
  Badge,
  Breadcrumb,
  CourseCover,
  IconClock,
  IconListChecks,
  IconPlayCircle,
  IconUsers,
  Sheet,
  formatCount,
  formatDurationLong,
} from "@vitaminvui/ui/v2";
import type { GateKind } from "@/components/v2/auth/AccountGate";
import { CourseAction, PriceLine, type Viewer } from "@/components/v2/course/CourseActionPanel";
import { CourseOutlinePublic } from "@/components/v2/course/CourseOutlinePublic";
import { PreviewBar } from "@/components/v2/PreviewBar";
import { StudentShell } from "@/components/v2/StudentShell";
import { publicConfig } from "@/lib/mock/v2/catalog";
import { getCourseDetail } from "@/lib/mock/v2/course-detail";
import { getLearnCourse } from "@/lib/mock/v2/learn";
import { one, routes } from "@/lib/v2/routes";

export const dynamic = "force-dynamic";

const VIEWERS: Viewer[] = ["guest", "can_buy", "in_cart", "can_register_free", "pending_approval", "owned"];
const VIEWER_LABEL: Record<Viewer, string> = {
  guest: "Khách",
  can_buy: "Chưa mua",
  in_cart: "Trong giỏ",
  can_register_free: "Miễn phí – chưa đăng ký",
  pending_approval: "Chờ duyệt",
  owned: "Đã sở hữu",
};

export default async function CourseDetailPreview({ params, searchParams }: PageProps<"/v2/khoa-hoc/[slug]">) {
  const { slug } = await params;
  const sp = await searchParams;
  const course = getCourseDetail(slug);
  if (!course) notFound();

  const viewerRaw = one(sp.viewer) as Viewer | undefined;
  const viewer: Viewer = viewerRaw && VIEWERS.includes(viewerRaw) ? viewerRaw : "guest";
  const paidEnabled = one(sp["thanh-toan"]) === "bat" ? true : publicConfig.paid_checkout_enabled;
  const loggedIn = viewer !== "guest";
  const chan = one(sp.chan);
  const gate: GateKind | undefined = chan === "xac-thuc" ? "verify" : chan === "phu-huynh" ? "parent-pending" : undefined;
  const resumeLessonId = viewer === "owned" ? (getLearnCourse(course.id)?.resume_lesson_id ?? null) : null;

  const href = (s: string, v: Viewer, paid = false) => `${routes.course(s)}?viewer=${v}${paid ? "&thanh-toan=bat" : ""}`;
  const paidSlug = "hinh-hoc-9-duong-tron";
  const freeSlug = "can-bac-hai-can-bac-ba";
  const isCur = (s: string, v: Viewer, paid = false) => slug === s && viewer === v && paidEnabled === (paid || publicConfig.paid_checkout_enabled);
  const variants = [
    { label: "Khách", href: href(paidSlug, "guest"), current: isCur(paidSlug, "guest") },
    { label: "Đã sở hữu", href: href(paidSlug, "owned"), current: isCur(paidSlug, "owned") },
    { label: "Có phí – thanh toán bật", href: href(paidSlug, "can_buy", true), current: isCur(paidSlug, "can_buy", true) },
    { label: "Trong giỏ – thanh toán bật", href: href(paidSlug, "in_cart", true), current: isCur(paidSlug, "in_cart", true) },
    { label: "Miễn phí", href: href(freeSlug, "can_register_free"), current: isCur(freeSlug, "can_register_free") && !chan },
    { label: "Miễn phí – chưa xác thực (403)", href: `${href(freeSlug, "can_register_free")}&chan=xac-thuc`, current: slug === freeSlug && chan === "xac-thuc" },
    { label: "Miễn phí – chờ phụ huynh (403)", href: `${href(freeSlug, "can_register_free")}&chan=phu-huynh`, current: slug === freeSlug && chan === "phu-huynh" },
    { label: "Miễn phí – chờ duyệt", href: href(freeSlug, "pending_approval"), current: isCur(freeSlug, "pending_approval") },
    { label: "Chưa có bài", href: routes.course("khoa-moi-chua-co-bai"), current: slug === "khoa-moi-chua-co-bai" },
    { label: "Không tồn tại (404)", href: routes.course("khong-co-khoa-nay") },
  ];

  const facts = [
    { icon: <IconPlayCircle size={18} />, text: `${course.lessons_count} bài học · ${formatDurationLong(course.total_duration_seconds)}` },
    { icon: <IconListChecks size={18} />, text: "Trắc nghiệm có lời giải sau bài" },
    {
      icon: <IconUsers size={18} />,
      text: course.enrollments_count > 0 ? `${formatCount(course.enrollments_count)} học sinh đã đăng ký` : "Chưa có học sinh đăng ký",
    },
  ];

  return (
    <StudentShell
      current="catalog"
      loggedIn={loggedIn}
      paidCheckoutEnabled={paidEnabled}
      hideBottomNav
      preview={<PreviewBar variants={variants} note={`Vai: ${VIEWER_LABEL[viewer]}${paidEnabled ? " · thanh toán bật" : " · thanh toán tạm khoá"}`} />}
    >
      <div className="mx-auto max-w-6xl px-4 pb-32 pt-6 sm:px-6 lg:pb-16">
        <Breadcrumb
          items={[
            { label: "Trang chủ", href: routes.home },
            { label: `Lớp ${course.grade_level}`, href: routes.catalogQuery({ grade: course.grade_level }) },
            { label: course.title },
          ]}
        />

        <div className="mt-4 grid gap-8 lg:grid-cols-[1fr_360px]">
          <div className="flex min-w-0 flex-col gap-8">
            <header className="flex flex-col gap-4">
              <div className="overflow-hidden rounded-card border border-line lg:hidden">
                <CourseCover title={course.title} gradeLevel={course.grade_level} subjectSlug={course.subjects[0]?.slug} size="hero" />
              </div>
              <div className="flex flex-wrap gap-2">
                <Badge tone="primary">Lớp {course.grade_level}</Badge>
                {course.subjects.map((s) => (
                  <Badge key={s.id}>{s.name}</Badge>
                ))}
                {course.is_free ? <Badge tone="free">Miễn phí</Badge> : null}
              </div>
              <h1 className="text-title font-extrabold tracking-heading text-ink md:text-title-lg">{course.title}</h1>
              {course.short_description ? <p className="max-w-2xl text-lg leading-relaxed text-ink-soft">{course.short_description}</p> : null}
              <ul className="flex flex-col gap-2 text-base text-ink sm:flex-row sm:flex-wrap sm:gap-x-6">
                {facts.map((f) => (
                  <li key={f.text} className="flex items-center gap-2">
                    <span className="text-primary">{f.icon}</span>
                    <span className="num">{f.text}</span>
                  </li>
                ))}
              </ul>
              {/* Mobile: giá + hành động ngay dưới tiêu đề; thanh dính đáy lặp lại khi cuộn. */}
              <div className="flex flex-col gap-3 rounded-card border border-line bg-surface p-4 lg:hidden">
                <PriceLine course={course} />
                <CourseAction course={course} viewer={viewer} paidEnabled={paidEnabled} resumeLessonId={resumeLessonId} gate={gate} />
              </div>
            </header>

            <Sheet as="section" aria-labelledby="mo-ta">
              <h2 id="mo-ta" className="text-heading font-extrabold tracking-heading text-ink">
                Giới thiệu khóa học
              </h2>
              {/* Bản thật: <CourseDescription html={course.description} /> (DOMPurify, FW2). */}
              <div className="mt-3 flex max-w-prose flex-col gap-3 text-base leading-relaxed text-ink">
                {course.description.split("\n\n").map((p, i) => (
                  <p key={i}>{p}</p>
                ))}
              </div>
            </Sheet>

            <section aria-labelledby="hoc-thu">
              <h2 id="hoc-thu" className="scroll-mt-24 text-heading font-extrabold tracking-heading text-ink">
                Nội dung khóa học
              </h2>
              <p className="num mt-1 text-sm text-ink-soft">
                {course.outline.length} chương · {course.lessons_count} bài · {formatDurationLong(course.total_duration_seconds)}
              </p>
              <div className="mt-4">
                <CourseOutlinePublic course={course} owned={viewer === "owned"} />
              </div>
            </section>

            <Sheet as="section" aria-labelledby="giao-vien">
              <h2 id="giao-vien" className="text-heading font-extrabold tracking-heading text-ink">
                Giáo viên
              </h2>
              <ul className="mt-4 grid gap-4 sm:grid-cols-2">
                {course.teachers.map((t) => (
                  <li key={t.id} className="flex gap-3">
                    <Avatar name={t.name} size="lg" />
                    <div>
                      <p className="font-semibold text-ink">{t.name}</p>
                      {t.bio ? <p className="text-sm text-ink-soft">{t.bio}</p> : null}
                    </div>
                  </li>
                ))}
              </ul>
            </Sheet>
          </div>

          {/* Desktop: thẻ dính bên phải. */}
          <aside aria-label="Đăng ký khóa học" className="hidden lg:block">
            <div className="sticky top-24 flex flex-col gap-4 overflow-hidden rounded-sheet border border-line bg-surface">
              <CourseCover title={course.title} gradeLevel={course.grade_level} subjectSlug={course.subjects[0]?.slug} />
              <div className="flex flex-col gap-4 px-5 pb-5">
                <PriceLine course={course} />
                <CourseAction course={course} viewer={viewer} paidEnabled={paidEnabled} resumeLessonId={resumeLessonId} gate={gate} />
                <p className="flex items-center gap-2 border-t border-line pt-4 text-sm text-ink-soft">
                  <IconClock size={16} />
                  Học trên điện thoại hoặc máy tính, mỗi lúc một thiết bị.
                </p>
              </div>
            </div>
          </aside>
        </div>
      </div>

      {/* Mobile: thanh hành động dính đáy. */}
      <div className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-surface px-4 pb-[calc(0.75rem+env(safe-area-inset-bottom))] pt-3 shadow-overlay lg:hidden">
        <div className="mx-auto flex max-w-xl items-center gap-4">
          <PriceLine course={course} size="md" />
          <div className="flex-1">
            <CourseAction course={course} viewer={viewer} paidEnabled={paidEnabled} resumeLessonId={resumeLessonId} gate={gate} compact />
          </div>
        </div>
      </div>
    </StudentShell>
  );
}
