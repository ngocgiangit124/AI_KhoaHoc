import { AdminPreviewShell } from "@/components/v2/AdminPreviewShell";
import { TeacherProfileForm } from "@/components/v2/TeacherProfileForm";
import { TEACHER_PROFILES } from "@/lib/mock/v2/teacher-profiles";

export const dynamic = "force-dynamic";

/** "Hồ sơ của tôi" của giáo viên (US-020 AC1). */
export default async function MyTeacherProfilePreview({ searchParams }: PageProps<"/v2/quan-tri/ho-so">) {
  const sp = await searchParams;
  const state = Array.isArray(sp["trang-thai"]) ? sp["trang-thai"][0] : sp["trang-thai"];
  // Mặc định: cô Hà (đã đồng ý, đang hiện, vừa được QLT sửa hộ). "chua-dong-y": thầy Nam mới tạo, hồ sơ trống.
  const profile = (state === "chua-dong-y" ? TEACHER_PROFILES.find((t) => t.id === 18) : TEACHER_PROFILES[0]) ?? TEACHER_PROFILES[0];
  if (!profile) return null;
  return (
    <AdminPreviewShell
      role="giao_vien"
      roles={["giao_vien"]}
      current="profile"
      basePath="/v2/quan-tri/ho-so"
      state={state}
      states={[
        { label: "Đã đồng ý, đang hiện" },
        { key: "chua-dong-y", label: "Hồ sơ mới, chưa đồng ý" },
        { key: "cat-anh", label: "Bước cắt ảnh" },
      ]}
    >
      <h1 className="text-title font-extrabold tracking-heading text-ink">Hồ sơ của tôi</h1>
      <p className="mt-1 max-w-3xl text-sm text-ink-soft">Ảnh và phần giới thiệu giúp học sinh, phụ huynh biết ai dạy khóa học. Chỉ hiện trên website khi bạn đồng ý công khai.</p>
      <div className="mt-6">
        <TeacherProfileForm key={state ?? "x"} profile={profile} mode="self" startWithCropper={state === "cat-anh"} />
      </div>
    </AdminPreviewShell>
  );
}
