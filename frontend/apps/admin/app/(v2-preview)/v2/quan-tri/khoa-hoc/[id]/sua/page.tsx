import Link from "next/link";
import { notFound } from "next/navigation";
import {
  Alert,
  Breadcrumb,
  Button,
  IconArrowRight,
  IconChevronLeft,
  IconExternalLink,
  IconX,
  LinkTabs,
} from "@vitaminvui/ui/v2";
import { AdminPreviewShell, roleFrom } from "@/components/v2/AdminPreviewShell";
import { CourseInfoForm } from "@/components/v2/CourseInfoForm";
import { CourseStatusBadge } from "@/components/v2/CourseStatusBadge";
import { CurriculumTree } from "@/components/v2/CurriculumTree";
import { LessonEditor } from "@/components/v2/LessonEditor";
import { ADMIN_COURSES, CHAPTERS } from "@/lib/mock/v2/data";

export const dynamic = "force-dynamic";

function one(v: string | string[] | undefined) {
  return Array.isArray(v) ? v[0] : v;
}

/** Sửa khóa học (US-009 §2.2–2.5): tab theo URL `?tab=thong-tin|chuong-bai`, bài đang sửa `?bai=`. */
export default async function AdminCourseEditPreview({ params, searchParams }: PageProps<"/v2/quan-tri/khoa-hoc/[id]/sua">) {
  const { id } = await params;
  const sp = await searchParams;
  const course = ADMIN_COURSES.find((c) => c.id === Number(id));
  if (!course) notFound();
  const role = roleFrom(sp["vai-tro"]);
  const isStaff = role !== "giao_vien";
  const tab = one(sp.tab) === "chuong-bai" ? "chuong-bai" : "thong-tin";
  const selectedId = Number(one(sp.bai)) || undefined;
  const chapters = course.id === 101 ? CHAPTERS : [];
  const lessons = chapters.flatMap((c) => c.lessons);
  const selected = lessons.find((l) => l.id === selectedId);

  const qs = (extra: Record<string, string | undefined>) => {
    const p = new URLSearchParams();
    if (role !== "admin") p.set("vai-tro", role);
    for (const [k, v] of Object.entries(extra)) if (v) p.set(k, v);
    const s = p.toString();
    return `/v2/quan-tri/khoa-hoc/${course.id}/sua${s ? `?${s}` : ""}`;
  };
  const listHref = `/v2/quan-tri/khoa-hoc${role !== "admin" ? `?vai-tro=${role}` : ""}`;

  return (
    <AdminPreviewShell role={role} current="courses" basePath={`/v2/quan-tri/khoa-hoc/${course.id}/sua`} extraQuery={tab === "chuong-bai" ? `tab=chuong-bai${selectedId ? `&bai=${selectedId}` : ""}` : ""}>
      <Breadcrumb items={[{ label: isStaff ? "Khóa học" : "Khóa học của tôi", href: listHref }, { label: course.title }]} />
      <div className="mt-3 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-title font-extrabold tracking-heading text-ink">{course.title}</h1>
            <CourseStatusBadge status={course.status} />
          </div>
          <p className="mt-1 text-sm text-ink-soft">
            Cập nhật 16:40, 05/10/2026
            {course.status === "published" ? (
              <>
                {" · "}
                <a href={`http://api.localhost:3000/v2/khoa-hoc/${course.slug}`} className="focus-ring inline-flex items-center gap-1 rounded font-semibold text-primary hover:underline">
                  Xem trang khóa học
                  <IconExternalLink size={14} />
                </a>
              </>
            ) : (
              " · Chưa có trang công khai (khóa chưa xuất bản)"
            )}
          </p>
        </div>
        {isStaff ? (
          <div className="flex shrink-0 gap-2">
            {course.status === "published" ? (
              <Button variant="secondary" size="sm">
                Ngừng bán
              </Button>
            ) : (
              <Button size="sm">Xuất bản</Button>
            )}
          </div>
        ) : null}
      </div>

      <LinkTabs
        className="mt-5"
        label="Phần của khóa học"
        items={[
          { href: qs({}), label: "Thông tin chung", current: tab === "thong-tin" },
          { href: qs({ tab: "chuong-bai" }), label: "Chương & bài", count: lessons.length, current: tab === "chuong-bai" },
        ]}
      />

      <div className="mt-5">
        {tab === "thong-tin" ? (
          <>
            <CourseInfoForm course={course} role={role} />
            {isStaff ? (
              <section aria-labelledby="nguy-hiem" className="mt-8 rounded-card border border-danger/40 bg-surface p-5">
                <h2 id="nguy-hiem" className="text-base font-semibold text-danger">
                  Xoá khóa học
                </h2>
                {course.enrollments_count > 0 ? (
                  <>
                    <p className="mt-1 text-sm text-ink">
                      Không thể xoá vì đã có {course.enrollments_count.toLocaleString("vi-VN")} học sinh đăng ký. Hãy chuyển sang <strong>Ngừng bán</strong>: khóa ẩn khỏi danh mục, học sinh đã mua vẫn học được.
                    </p>
                    <Button variant="danger" size="sm" disabled className="mt-3">
                      Xoá khóa học
                    </Button>
                  </>
                ) : (
                  <>
                    <p className="mt-1 text-sm text-ink">Xoá khóa học cùng toàn bộ chương và bài. Hành động này không thể hoàn tác.</p>
                    <Button variant="danger" size="sm" className="mt-3">
                      Xoá khóa học
                    </Button>
                  </>
                )}
              </section>
            ) : null}
          </>
        ) : chapters.length === 0 ? (
          <Alert tone="info" title="Khóa học chưa có chương nào">
            Thêm chương và ít nhất 1 bài học để có thể xuất bản. Mở khóa “Hình học 9: Đường tròn” để xem cây mẫu.
          </Alert>
        ) : (
          <div className="grid gap-6 xl:grid-cols-[1fr_400px]">
            <div className="flex min-w-0 flex-col gap-3">
              <p className="text-sm text-ink-soft">Kéo biểu tượng ⠿ để sắp xếp chương và bài. Dùng bàn phím: chọn bài rồi dùng nút “Lên/Xuống” trong khung sửa.</p>
              <CurriculumTree chapters={chapters} selectedId={selectedId} lessonHref={(lid) => qs({ tab: "chuong-bai", bai: String(lid) })} />
            </div>
            <aside aria-labelledby="sua-bai" className="xl:sticky xl:top-6 xl:self-start">
              {selected ? (
                <div className="flex flex-col gap-4 rounded-card border border-line bg-surface p-5 shadow-raised">
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <p className="text-xs font-medium text-ink-soft">Sửa bài học</p>
                      <h2 id="sua-bai" className="text-base font-semibold text-ink">
                        {selected.title}
                      </h2>
                    </div>
                    <Link href={qs({ tab: "chuong-bai" })} scroll={false} aria-label="Đóng khung sửa bài" className="focus-ring inline-flex size-9 items-center justify-center rounded-control text-ink hover:bg-sunken">
                      <IconX size={18} />
                    </Link>
                  </div>
                  <div className="flex gap-2">
                    <Button variant="secondary" size="sm" leadingIcon={<IconChevronLeft size={16} className="rotate-90" />}>
                      Lên
                    </Button>
                    <Button variant="secondary" size="sm" leadingIcon={<IconChevronLeft size={16} className="-rotate-90" />}>
                      Xuống
                    </Button>
                  </div>
                  <LessonEditor key={selected.id} lesson={selected} />
                </div>
              ) : (
                <div className="flex flex-col gap-2 rounded-card border border-dashed border-line-strong p-5 text-sm text-ink-soft">
                  <h2 id="sua-bai" className="text-base font-semibold text-ink">
                    Chọn một bài để sửa
                  </h2>
                  <p>Bấm vào bài trong danh sách bên cạnh. Gợi ý xem các trạng thái video:</p>
                  <ul className="flex flex-col gap-1">
                    {[
                      { id: 310, label: "Bài 10 — video lỗi (VIDEO_INVALID)" },
                      { id: 309, label: "Bài 9 — đang tải lên" },
                      { id: 308, label: "Bài 8 — đang xử lý" },
                      { id: 301, label: "Bài 1 — học thử, link YouTube" },
                      { id: 311, label: "Bài 11 — chưa có video" },
                    ].map((x) => (
                      <li key={x.id}>
                        <Link href={qs({ tab: "chuong-bai", bai: String(x.id) })} scroll={false} className="focus-ring inline-flex min-h-9 items-center gap-1 rounded font-semibold text-primary hover:underline">
                          {x.label}
                          <IconArrowRight size={14} />
                        </Link>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </aside>
          </div>
        )}
      </div>
    </AdminPreviewShell>
  );
}
