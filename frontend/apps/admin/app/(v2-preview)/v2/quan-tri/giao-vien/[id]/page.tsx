import { notFound } from "next/navigation";
import { Breadcrumb } from "@vitaminvui/ui/v2";
import { AdminPreviewShell, roleFrom } from "@/components/v2/AdminPreviewShell";
import { TeacherProfileForm } from "@/components/v2/TeacherProfileForm";
import { TEACHER_PROFILES } from "@/lib/mock/v2/teacher-profiles";

export const dynamic = "force-dynamic";

/** Admin/QLT sửa hộ hồ sơ giáo viên (US-020 BR6, AC8): không đồng ý thay. */
export default async function AdminTeacherProfilePreview({ params, searchParams }: PageProps<"/v2/quan-tri/giao-vien/[id]">) {
  const { id } = await params;
  const sp = await searchParams;
  const profile = TEACHER_PROFILES.find((t) => t.id === Number(id));
  if (!profile) notFound();
  const role = roleFrom(sp["vai-tro"]) === "quan_ly_trang" ? "quan_ly_trang" : "admin";
  const roleQ = role === "admin" ? "" : `?vai-tro=${role}`;
  return (
    <AdminPreviewShell role={role} roles={["admin", "quan_ly_trang"]} current="teachers" basePath={`/v2/quan-tri/giao-vien/${profile.id}`}>
      <Breadcrumb items={[{ label: "Giáo viên trên trang chủ", href: `/v2/quan-tri/giao-vien${roleQ}` }, { label: profile.name }]} />
      <h1 className="mt-3 text-title font-extrabold tracking-heading text-ink">Hồ sơ công khai — {profile.name}</h1>
      <p className="mt-1 text-sm text-ink-soft">Bạn đang sửa hộ giáo viên. Giáo viên sẽ thấy “Chỉnh sửa gần nhất bởi” bạn trong “Hồ sơ của tôi”.</p>
      <div className="mt-6">
        <TeacherProfileForm profile={profile} mode="admin" />
      </div>
    </AdminPreviewShell>
  );
}
