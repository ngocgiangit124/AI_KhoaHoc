import { Breadcrumb } from "@vitaminvui/ui/v2";
import { AdminPreviewShell, roleFrom } from "@/components/v2/AdminPreviewShell";
import { CourseInfoForm } from "@/components/v2/CourseInfoForm";
import { STAFF, type AdminCourse } from "@/lib/mock/v2/data";

export const dynamic = "force-dynamic";

/** Tạo khóa học (US-009 §2.2, POST /admin/courses multipart, FA3). Khóa mới luôn `draft`. */
export default async function AdminCourseCreatePreview({ searchParams }: PageProps<"/v2/quan-tri/khoa-hoc/tao">) {
  const sp = await searchParams;
  const role = roleFrom(sp["vai-tro"]);
  const roleQ = role === "admin" ? "" : `?vai-tro=${role}`;
  const blank: AdminCourse = {
    id: 0, title: "", slug: "", short_description: null, grade_level: 9, price: 0, thumbnail_url: null, status: "draft", published_at: null,
    manual_order: null, enrollments_count: 0, subjects: [], teachers: role === "giao_vien" ? [{ id: STAFF.giao_vien.id, name: STAFF.giao_vien.name }] : [],
    created_by: null, created_at: "", updated_at: "",
  };
  return (
    <AdminPreviewShell role={role} current="courses" basePath="/v2/quan-tri/khoa-hoc/tao">
      <Breadcrumb items={[{ label: role === "giao_vien" ? "Khóa học của tôi" : "Khóa học", href: `/v2/quan-tri/khoa-hoc${roleQ}` }, { label: "Tạo khóa học" }]} />
      <h1 className="mt-3 text-title font-extrabold tracking-heading text-ink">Tạo khóa học</h1>
      <p className="mt-1 text-sm text-ink-soft">Điền thông tin chung trước; chương, bài học và video thêm ở bước sau.</p>
      <div className="mt-6">
        <CourseInfoForm course={blank} role={role} mode="create" />
      </div>
    </AdminPreviewShell>
  );
}
