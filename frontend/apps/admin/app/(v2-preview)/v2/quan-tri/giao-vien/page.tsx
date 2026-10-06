import { AdminPreviewShell, roleFrom } from "@/components/v2/AdminPreviewShell";
import { HomepageTeachersManager } from "@/components/v2/HomepageTeachersManager";
import { HOMEPAGE_TEACHER_LIMIT, TEACHER_PROFILES } from "@/lib/mock/v2/teacher-profiles";

export const dynamic = "force-dynamic";

/** Admin/QLT: giáo viên hiện ở trang chủ (US-020). */
export default async function HomepageTeachersPreview({ searchParams }: PageProps<"/v2/quan-tri/giao-vien">) {
  const sp = await searchParams;
  const role = roleFrom(sp["vai-tro"]) === "quan_ly_trang" ? "quan_ly_trang" : "admin";
  const state = Array.isArray(sp["trang-thai"]) ? sp["trang-thai"][0] : sp["trang-thai"];
  const full = state === "du-6";
  const data = full ? TEACHER_PROFILES.map((t) => (t.id === 14 ? { ...t, show_on_homepage: true, homepage_order: 6 } : t)) : TEACHER_PROFILES;
  const roleQ = role === "admin" ? "" : `?vai-tro=${role}`;
  return (
    <AdminPreviewShell
      role={role}
      roles={["admin", "quan_ly_trang"]}
      current="teachers"
      basePath="/v2/quan-tri/giao-vien"
      state={state}
      states={[{ label: "Đang bật 5/6" }, { key: "du-6", label: "Đã đủ 6 (thử bật thêm)" }]}
    >
      <h1 className="text-title font-extrabold tracking-heading text-ink">Giáo viên trên trang chủ</h1>
      <p className="mt-1 max-w-3xl text-sm text-ink-soft">
        Trang chủ hiện tối đa {HOMEPAGE_TEACHER_LIMIT} giáo viên theo thứ tự dưới đây, và chỉ những người đã tự đồng ý công khai, có ảnh, có phần giới thiệu, có khóa đang bán. Thay đổi hiện trên website sau tối đa 1 phút.
      </p>
      <div className="mt-6">
        <HomepageTeachersManager key={state ?? "x"} initial={data} editBase="/v2/quan-tri/giao-vien" editQuery={roleQ} />
      </div>
    </AdminPreviewShell>
  );
}
