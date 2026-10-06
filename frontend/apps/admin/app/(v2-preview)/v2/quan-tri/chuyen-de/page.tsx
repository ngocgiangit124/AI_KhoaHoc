import { AdminPreviewShell, roleFrom } from "@/components/v2/AdminPreviewShell";
import { SubjectsManager } from "@/components/v2/SubjectsManager";
import { ADMIN_SUBJECTS } from "@/lib/mock/v2/ops";

export const dynamic = "force-dynamic";

/** Chuyên đề (US-011, GET /admin/subjects). Giáo viên tạm xem chỉ-đọc (chờ PO câu 8). */
export default async function SubjectsPreview({ searchParams }: PageProps<"/v2/quan-tri/chuyen-de">) {
  const sp = await searchParams;
  const role = roleFrom(sp["vai-tro"]);
  const state = Array.isArray(sp["trang-thai"]) ? sp["trang-thai"][0] : sp["trang-thai"];
  const readOnly = role === "giao_vien";
  // GV chỉ nhận chuyên đề active (T06).
  const data = state === "rong" ? [] : readOnly ? ADMIN_SUBJECTS.filter((s) => s.status === "active") : ADMIN_SUBJECTS;
  return (
    <AdminPreviewShell role={role} current="subjects" basePath="/v2/quan-tri/chuyen-de" state={state} states={[{ label: "Có dữ liệu" }, { key: "rong", label: "Rỗng" }]}>
      <h1 className="text-title font-extrabold tracking-heading text-ink">Chuyên đề</h1>
      <p className="mt-1 max-w-3xl text-sm text-ink-soft">Chuyên đề dùng để lọc khóa học ở danh mục và gán cho khóa học. Chuyên đề bị ẩn không hiện ở bộ lọc công khai.</p>
      <div className="mt-6">
        <SubjectsManager key={`${role}-${state}`} initial={data} readOnly={readOnly} />
      </div>
    </AdminPreviewShell>
  );
}
