import { AdminPreviewShell, roleFrom } from "@/components/v2/AdminPreviewShell";
import { RequestsManager } from "@/components/v2/RequestsManager";
import { ENROLLMENT_REQUESTS, TEACHER_FREE_COURSE_IDS, type EnrollmentRequest } from "@/lib/mock/v2/ops";

export const dynamic = "force-dynamic";

/** Duyệt đăng ký khóa miễn phí (US-012). Giáo viên chỉ thấy khóa mình phụ trách. */
export default async function RequestsPreview({ searchParams }: PageProps<"/v2/quan-tri/duyet-dang-ky">) {
  const sp = await searchParams;
  const role = roleFrom(sp["vai-tro"]);
  const raw = Array.isArray(sp.status) ? sp.status[0] : sp.status;
  const status: EnrollmentRequest["status"] = raw === "active" || raw === "rejected" ? raw : "pending_approval";
  const data = role === "giao_vien" ? ENROLLMENT_REQUESTS.filter((r) => TEACHER_FREE_COURSE_IDS.includes(r.course.id)) : ENROLLMENT_REQUESTS;
  const base = "/v2/quan-tri/duyet-dang-ky";
  const href = (s?: string) => {
    const p = new URLSearchParams();
    if (role !== "admin") p.set("vai-tro", role);
    if (s) p.set("status", s);
    const q = p.toString();
    return q ? `${base}?${q}` : base;
  };
  return (
    <AdminPreviewShell role={role} current="requests" basePath={base} extraQuery={status !== "pending_approval" ? `status=${status}` : ""}>
      <h1 className="text-title font-extrabold tracking-heading text-ink">Duyệt đăng ký khóa miễn phí</h1>
      <p className="mt-1 max-w-3xl text-sm text-ink-soft">
        {role === "giao_vien" ? "Yêu cầu của các khóa miễn phí bạn phụ trách." : "Yêu cầu của mọi khóa miễn phí."} Yêu cầu gửi sớm nhất ở trên cùng. Học sinh nhận email khi được duyệt hoặc từ chối.
      </p>
      <div className="mt-6">
        <RequestsManager key={`${role}-${status}`} initial={data} status={status} tabHref={{ pending_approval: href(), active: href("active"), rejected: href("rejected") }} />
      </div>
    </AdminPreviewShell>
  );
}
