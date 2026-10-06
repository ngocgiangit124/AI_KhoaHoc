"use client";

import Link from "next/link";
import { useState } from "react";
import {
  Alert,
  Badge,
  Button,
  ConfirmDialog,
  DataTable,
  Dialog,
  EmptyState,
  Field,
  IconCopy,
  IconPlus,
  IconSearch,
  Select,
  TextInput,
  formatDateTime,
  useToast,
  type Column,
} from "@vitaminvui/ui/v2";
import { ROLE_LABEL, type StaffRole } from "@/lib/mock/v2/data";
import { TEACHER_COURSES, type StaffAccount } from "@/lib/mock/v2/ops";

type Action = { kind: "lock" | "unlock" | "reset"; staff: StaffAccount } | { kind: "role"; staff: StaffAccount } | null;

const SAMPLE_PASSWORD = "Vv7#pQ2m!kZ9rT4x&bL6";

/**
 * Tài khoản staff (US-016 phần B, FA10; API T33, chỉ Admin).
 * - Tạo / đặt lại mật khẩu → hộp "Mật khẩu khởi tạo" hiện MỘT lần (20 ký tự), nút sao chép, cảnh báo.
 *   Người nhận phải đổi mật khẩu khi đăng nhập lần đầu (mật khẩu staff tối thiểu 12 ký tự).
 * - Không tự khoá/đổi vai trò/đặt lại chính mình (CANNOT_MODIFY_SELF); không khoá/hạ Admin hoạt động cuối (LAST_ADMIN):
 *   nút khoá kèm chữ giải thích ngay tại dòng.
 * - Đổi vai trò giáo viên → API trả `released_course_ids`: cảnh báo "N khóa không còn giáo viên phụ trách".
 */
export function StaffManager({ initial }: { initial: StaffAccount[] }) {
  const toast = useToast();
  const [items, setItems] = useState(initial);
  const [q, setQ] = useState("");
  const [roleFilter, setRoleFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [creating, setCreating] = useState(false);
  const [createErr, setCreateErr] = useState<{ name?: string; email?: string }>({});
  const [password, setPassword] = useState<{ name: string } | null>(null);
  const [action, setAction] = useState<Action>(null);
  const [newRole, setNewRole] = useState<StaffRole>("quan_ly_trang");
  const [released, setReleased] = useState<{ name: string; courses: Array<{ id: number; title: string }> } | null>(null);
  const [loading, setLoading] = useState(false);

  const activeAdmins = items.filter((s) => s.role === "admin" && s.status === "active").length;
  const rows = items.filter(
    (s) =>
      (!q || `${s.name} ${s.email}`.toLowerCase().includes(q.toLowerCase())) && (!roleFilter || s.role === roleFilter) && (!statusFilter || s.status === statusFilter),
  );

  function blockReason(s: StaffAccount): string | null {
    if (s.is_self) return "Đây là tài khoản của bạn.";
    if (s.role === "admin" && s.status === "active" && activeAdmins <= 1) return "Admin đang hoạt động duy nhất.";
    return null;
  }

  function run(fn: () => void) {
    setLoading(true);
    setTimeout(() => {
      fn();
      setLoading(false);
      setAction(null);
    }, 600);
  }

  const columns: Array<Column<StaffAccount>> = [
    {
      key: "name",
      header: "Họ tên",
      cell: (s) => (
        <div>
          <p className="font-semibold">
            {s.name}
            {s.is_self ? <span className="font-normal text-ink-soft"> (bạn)</span> : null}
          </p>
          <p className="text-xs text-ink-soft">{s.email}</p>
        </div>
      ),
    },
    { key: "role", header: "Vai trò", cell: (s) => <Badge size="sm" tone={s.role === "admin" ? "primary" : "neutral"}>{ROLE_LABEL[s.role]}</Badge> },
    {
      key: "status",
      header: "Trạng thái",
      cell: (s) => (
        <div className="flex flex-col items-start gap-1">
          <Badge size="sm" dot tone={s.status === "active" ? "success" : "danger"}>
            {s.status === "active" ? "Đang hoạt động" : "Đã khoá"}
          </Badge>
          {s.must_change_password ? <span className="text-xs text-warning">Chưa đổi mật khẩu lần đầu</span> : null}
        </div>
      ),
    },
    { key: "login", header: "Đăng nhập gần nhất", hideBelow: "lg", cell: (s) => <span className="num whitespace-nowrap">{s.last_login_at ? formatDateTime(s.last_login_at) : "Chưa đăng nhập"}</span> },
    {
      key: "act",
      header: <span className="sr-only">Thao tác</span>,
      align: "right",
      cell: (s) => {
        const reason = blockReason(s);
        if (s.is_self) return <span className="text-xs text-ink-soft">Không tự khoá, đổi vai trò hay đặt lại mật khẩu của chính mình</span>;
        return (
          <div className="flex flex-col items-end gap-1">
            <div className="flex flex-wrap justify-end gap-1">
              <Button size="sm" variant="ghost" onClick={() => { setNewRole(s.role === "giao_vien" ? "quan_ly_trang" : "giao_vien"); setAction({ kind: "role", staff: s }); }} disabled={Boolean(reason)}>
                Đổi vai trò
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setAction({ kind: "reset", staff: s })}>
                Đặt lại mật khẩu
              </Button>
              {s.status === "active" ? (
                <Button size="sm" variant="ghost" className="text-danger hover:bg-danger-soft" onClick={() => setAction({ kind: "lock", staff: s })} disabled={Boolean(reason)}>
                  Khoá
                </Button>
              ) : (
                <Button size="sm" variant="ghost" onClick={() => setAction({ kind: "unlock", staff: s })}>
                  Mở khoá
                </Button>
              )}
            </div>
            {reason ? <span className="text-xs text-ink-soft">Không khoá/đổi vai trò được: {reason}</span> : null}
          </div>
        );
      },
    },
  ];

  const a = action;
  return (
    <div className="flex flex-col gap-4">
      {released ? (
        <Alert tone="warning" title={`${released.courses.length} khóa không còn giáo viên phụ trách, hãy gán lại`}>
          <p>{released.name} không còn là giáo viên nên đã được gỡ khỏi:</p>
          <ul className="mt-1 list-disc pl-5">
            {released.courses.map((c) => (
              <li key={c.id}>
                <Link href={`/v2/quan-tri/khoa-hoc/${c.id}/sua`} className="font-semibold underline underline-offset-2">
                  {c.title}
                </Link>
              </li>
            ))}
          </ul>
        </Alert>
      ) : null}

      <div className="flex flex-col gap-3 rounded-card border border-line bg-surface p-3 md:flex-row md:items-center">
        <div className="md:w-72">
          <label htmlFor="staff-q" className="sr-only">
            Tìm theo tên hoặc email
          </label>
          <TextInput id="staff-q" size="sm" type="search" placeholder="Tìm theo tên hoặc email" value={q} onChange={(e) => setQ(e.target.value)} leadingIcon={<IconSearch size={16} />} />
        </div>
        <div className="md:w-44">
          <label htmlFor="staff-role" className="sr-only">
            Vai trò
          </label>
          <Select id="staff-role" size="sm" value={roleFilter} onChange={(e) => setRoleFilter(e.target.value)}>
            <option value="">Mọi vai trò</option>
            {(Object.keys(ROLE_LABEL) as StaffRole[]).map((r) => (
              <option key={r} value={r}>
                {ROLE_LABEL[r]}
              </option>
            ))}
          </Select>
        </div>
        <div className="md:w-44">
          <label htmlFor="staff-status" className="sr-only">
            Trạng thái
          </label>
          <Select id="staff-status" size="sm" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
            <option value="">Mọi trạng thái</option>
            <option value="active">Đang hoạt động</option>
            <option value="locked">Đã khoá</option>
          </Select>
        </div>
        <Button size="sm" leadingIcon={<IconPlus size={16} />} className="md:ml-auto" onClick={() => { setCreateErr({}); setCreating(true); }}>
          Tạo tài khoản
        </Button>
      </div>

      <DataTable caption="Tài khoản staff" columns={columns} rows={rows} rowKey={(s) => s.id} density="compact" empty={<EmptyState size="inline" icon={<IconSearch size={24} />} title="Không có tài khoản khớp bộ lọc" headingLevel="h2" />} />

      {/* Tạo tài khoản */}
      <Dialog
        open={creating}
        onClose={() => setCreating(false)}
        title="Tạo tài khoản staff"
        description="Mật khẩu khởi tạo được sinh ngẫu nhiên và chỉ hiện một lần sau khi tạo."
        size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setCreating(false)}>
              Huỷ
            </Button>
            <Button
              type="submit"
              form="create-staff"
              loading={loading}
              loadingText="Đang tạo…"
            >
              Tạo tài khoản
            </Button>
          </>
        }
      >
        <form
          id="create-staff"
          noValidate
          className="flex flex-col gap-4"
          onSubmit={(e) => {
            e.preventDefault();
            const f = new FormData(e.currentTarget);
            const name = String(f.get("name") ?? "").trim();
            const email = String(f.get("email") ?? "").trim().toLowerCase();
            const err: typeof createErr = {};
            if (!name) err.name = "Vui lòng nhập họ và tên.";
            if (!/^\S+@\S+\.\S+$/.test(email)) err.email = "Email chưa đúng định dạng.";
            else if (items.some((s) => s.email === email)) err.email = "Email đã được sử dụng.";
            setCreateErr(err);
            if (Object.keys(err).length) return;
            const role = String(f.get("role")) as StaffRole;
            run(() => {
              setItems([{ id: Date.now(), name, email, role, status: "active", must_change_password: true, last_login_at: null, password_changed_at: null, created_at: new Date().toISOString(), is_self: false }, ...items]);
              setCreating(false);
              setPassword({ name });
            });
          }}
        >
          <Field label="Họ và tên" required error={createErr.name}>
            <TextInput name="name" size="sm" maxLength={100} />
          </Field>
          <Field label="Email" required error={createErr.email}>
            <TextInput name="email" size="sm" type="email" />
          </Field>
          <Field label="Vai trò" required>
            <Select name="role" size="sm" defaultValue="giao_vien">
              <option value="giao_vien">Giáo viên</option>
              <option value="quan_ly_trang">Quản lý trang</option>
              <option value="admin">Admin</option>
            </Select>
          </Field>
        </form>
      </Dialog>

      {/* Mật khẩu khởi tạo — hiện một lần */}
      <Dialog
        open={password !== null}
        onClose={() => setPassword(null)}
        dismissible={false}
        title="Mật khẩu khởi tạo"
        size="sm"
        footer={<Button onClick={() => setPassword(null)}>Đã sao chép, đóng</Button>}
      >
        <div className="flex flex-col gap-4">
          <Alert tone="warning" title="Mật khẩu chỉ hiện một lần">
            Gửi cho {password?.name} qua kênh an toàn. Khi đăng nhập lần đầu, họ phải đặt mật khẩu mới tối thiểu 12 ký tự.
          </Alert>
          <div className="flex items-center gap-2 rounded-control border border-line-strong bg-sunken p-3">
            <code className="flex-1 break-all font-mono text-base text-ink">{SAMPLE_PASSWORD}</code>
            <Button
              size="sm"
              variant="secondary"
              leadingIcon={<IconCopy size={16} />}
              onClick={() => {
                void navigator.clipboard?.writeText(SAMPLE_PASSWORD);
                toast.show({ tone: "success", title: "Đã sao chép mật khẩu" });
              }}
            >
              Sao chép
            </Button>
          </div>
        </div>
      </Dialog>

      {/* Khoá / mở khoá / đặt lại mật khẩu */}
      <ConfirmDialog
        open={a !== null && a.kind !== "role"}
        onClose={() => setAction(null)}
        loading={loading}
        tone={a?.kind === "unlock" ? "primary" : "danger"}
        title={a ? (a.kind === "lock" ? `Khoá tài khoản ${a.staff.name}?` : a.kind === "unlock" ? `Mở khoá tài khoản ${a.staff.name}?` : `Đặt lại mật khẩu cho ${a.staff.name}?`) : ""}
        description={
          a?.kind === "lock"
            ? "Tài khoản sẽ không đăng nhập được và bị từ chối ngay ở thao tác tiếp theo, cho tới khi được mở khoá."
            : a?.kind === "unlock"
              ? "Tài khoản đăng nhập lại được bình thường (phải đăng nhập lại từ đầu)."
              : `Hệ thống sinh mật khẩu mới, đăng xuất ${a?.staff.name ?? ""} khỏi mọi thiết bị và buộc đổi mật khẩu ở lần đăng nhập kế tiếp.`
        }
        confirmLabel={a?.kind === "lock" ? "Khoá tài khoản" : a?.kind === "unlock" ? "Mở khoá" : "Đặt lại mật khẩu"}
        onConfirm={() => {
          if (!a || a.kind === "role") return;
          const s = a.staff;
          run(() => {
            if (a.kind === "reset") {
              setItems(items.map((x) => (x.id === s.id ? { ...x, must_change_password: true } : x)));
              setPassword({ name: s.name });
            } else {
              setItems(items.map((x) => (x.id === s.id ? { ...x, status: a.kind === "lock" ? "locked" : "active" } : x)));
              toast.show({ tone: "success", title: a.kind === "lock" ? `Đã khoá tài khoản ${s.name}` : `Đã mở khoá tài khoản ${s.name}` });
            }
          });
        }}
      />

      {/* Đổi vai trò */}
      <Dialog
        open={a?.kind === "role"}
        onClose={() => setAction(null)}
        title={`Đổi vai trò của ${a?.staff.name ?? ""}`}
        description="Người này sẽ bị đăng xuất và phải đăng nhập lại (Admin/Quản lý trang cần xác thực 2 lớp)."
        size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setAction(null)}>
              Huỷ
            </Button>
            <Button
              loading={loading}
              loadingText="Đang đổi…"
              disabled={a?.kind === "role" && newRole === a.staff.role}
              onClick={() => {
                if (a?.kind !== "role") return;
                const s = a.staff;
                run(() => {
                  setItems(items.map((x) => (x.id === s.id ? { ...x, role: newRole } : x)));
                  const lost = s.role === "giao_vien" && newRole !== "giao_vien" ? TEACHER_COURSES[s.id] ?? [] : [];
                  setReleased(lost.length ? { name: s.name, courses: lost } : null);
                  toast.show({ tone: "success", title: `Đã đổi vai trò của ${s.name}` });
                });
              }}
            >
              Đổi vai trò
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-3">
          <Field label="Vai trò mới" required>
            <Select size="sm" value={newRole} onChange={(e) => setNewRole(e.target.value as StaffRole)}>
              {(Object.keys(ROLE_LABEL) as StaffRole[]).map((r) => (
                <option key={r} value={r}>
                  {ROLE_LABEL[r]}
                </option>
              ))}
            </Select>
          </Field>
          {a?.kind === "role" && a.staff.role === "giao_vien" && newRole !== "giao_vien" && (TEACHER_COURSES[a.staff.id]?.length ?? 0) > 0 ? (
            <Alert tone="warning" title={`${TEACHER_COURSES[a.staff.id]?.length} khóa sẽ mất giáo viên phụ trách`}>
              Người này sẽ bị gỡ khỏi các khóa đang phụ trách. Đổi lại thành giáo viên cũng không tự gán lại.
            </Alert>
          ) : null}
        </div>
      </Dialog>
    </div>
  );
}
