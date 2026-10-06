"use client";

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
  IconButton,
  IconPencil,
  IconPlus,
  IconSearch,
  IconShapes,
  IconTrash,
  Switch,
  TextInput,
  useToast,
  type Column,
} from "@vitaminvui/ui/v2";
import type { AdminSubject } from "@/lib/mock/v2/ops";

function slugify(name: string) {
  return name
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/đ/gi, "d")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "");
}

/**
 * Chuyên đề (US-011, FA2; API T06). Tạo/sửa trong hộp thoại, ẩn/hiện bằng công tắc, xoá có 2 nhánh:
 * đang gán khóa → không xoá được, gợi ý Ẩn; chưa gán → xác nhận xoá. Giáo viên: chỉ đọc (chờ PO, câu 8).
 * TODO(dev): POST/PUT/DELETE /admin/subjects, PATCH status; 422 errors.name "Chuyên đề đã tồn tại."; 409 SUBJECT_IN_USE.
 */
export function SubjectsManager({ initial, readOnly }: { initial: AdminSubject[]; readOnly: boolean }) {
  const toast = useToast();
  const [items, setItems] = useState(initial);
  const [q, setQ] = useState("");
  const [editing, setEditing] = useState<AdminSubject | "new" | null>(null);
  const [name, setName] = useState("");
  const [nameError, setNameError] = useState<string>();
  const [deleting, setDeleting] = useState<AdminSubject | null>(null);

  const shown = items.filter((s) => s.name.toLowerCase().includes(q.trim().toLowerCase()));

  function openForm(s: AdminSubject | "new") {
    setEditing(s);
    setName(s === "new" ? "" : s.name);
    setNameError(undefined);
  }

  function saveForm() {
    const n = name.trim().replace(/\s+/g, " ");
    if (!n) return setNameError("Vui lòng nhập tên chuyên đề.");
    const dup = items.find((x) => slugify(x.name) === slugify(n) && (editing === "new" || x.id !== editing?.id));
    if (dup) return setNameError("Chuyên đề đã tồn tại.");
    if (editing === "new") {
      setItems([...items, { id: Date.now(), name: n, slug: slugify(n), status: "active", courses_count: 0, created_at: "", updated_at: "" }]);
    } else if (editing) {
      setItems(items.map((x) => (x.id === editing.id ? { ...x, name: n } : x)));
    }
    setEditing(null);
    toast.show({ tone: "success", title: "Đã lưu chuyên đề" });
  }

  function setStatus(s: AdminSubject, active: boolean) {
    setItems(items.map((x) => (x.id === s.id ? { ...x, status: active ? "active" : "hidden" } : x)));
    toast.show({ tone: "success", title: active ? "Đã hiển thị lại chuyên đề" : "Đã ẩn chuyên đề khỏi bộ lọc công khai" });
  }

  const columns: Array<Column<AdminSubject>> = [
    {
      key: "name",
      header: "Tên chuyên đề",
      cell: (s) => (
        <div>
          <p className="font-semibold">{s.name}</p>
          <p className="text-xs text-ink-soft">/{s.slug}</p>
        </div>
      ),
    },
    { key: "count", header: "Khóa học đang gán", align: "right", cell: (s) => s.courses_count },
    {
      key: "status",
      header: "Hiển thị công khai",
      cell: (s) =>
        readOnly ? (
          <Badge size="sm" dot tone={s.status === "active" ? "success" : "neutral"}>
            {s.status === "active" ? "Đang hiện" : "Đã ẩn"}
          </Badge>
        ) : (
          <Switch checked={s.status === "active"} label={`Hiển thị chuyên đề ${s.name}`} onCheckedChange={(v) => setStatus(s, v)} />
        ),
    },
    ...(readOnly
      ? []
      : [
          {
            key: "act",
            header: <span className="sr-only">Thao tác</span>,
            align: "right" as const,
            cell: (s: AdminSubject) => (
              <div className="flex justify-end gap-1">
                <IconButton size="sm" label={`Sửa ${s.name}`} icon={<IconPencil size={16} />} onClick={() => openForm(s)} />
                <IconButton size="sm" label={`Xoá ${s.name}`} icon={<IconTrash size={16} />} onClick={() => setDeleting(s)} className="hover:bg-danger-soft hover:text-danger" />
              </div>
            ),
          },
        ]),
  ];

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="w-full sm:max-w-xs">
          <label htmlFor="subject-q" className="sr-only">
            Tìm chuyên đề
          </label>
          <TextInput id="subject-q" size="sm" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Tìm theo tên" leadingIcon={<IconSearch size={16} />} />
        </div>
        {readOnly ? null : (
          <Button size="sm" leadingIcon={<IconPlus size={16} />} onClick={() => openForm("new")}>
            Tạo chuyên đề
          </Button>
        )}
      </div>
      {readOnly ? (
        <Alert tone="info" title="Chế độ chỉ xem">
          Giáo viên xem được danh sách chuyên đề để chọn cho khóa học; Admin/Quản lý trang tạo, sửa và ẩn chuyên đề.
        </Alert>
      ) : null}
      <DataTable
        caption="Danh sách chuyên đề"
        columns={columns}
        rows={shown}
        rowKey={(s) => s.id}
        density="compact"
        empty={
          q ? (
            <EmptyState size="inline" icon={<IconSearch size={24} />} title="Không có chuyên đề khớp từ khóa" headingLevel="h2" />
          ) : (
            <EmptyState size="inline" icon={<IconShapes size={24} />} title="Chưa có chuyên đề nào" action={readOnly ? undefined : <Button size="sm" onClick={() => openForm("new")}>Tạo chuyên đề đầu tiên</Button>} headingLevel="h2" />
          )
        }
      />

      <Dialog
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={editing === "new" ? "Tạo chuyên đề" : "Sửa chuyên đề"}
        size="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setEditing(null)}>
              Huỷ
            </Button>
            <Button onClick={saveForm}>Lưu</Button>
          </>
        }
      >
        <Field
          label="Tên chuyên đề"
          required
          error={nameError}
          hint={editing === "new" ? `Đường dẫn: /${slugify(name) || "…"}` : "Đổi tên không đổi đường dẫn đã có."}
        >
          <TextInput size="sm" value={name} maxLength={100} onChange={(e) => setName(e.target.value)} />
        </Field>
      </Dialog>

      {deleting && deleting.courses_count > 0 ? (
        <Dialog
          open
          onClose={() => setDeleting(null)}
          title="Không thể xoá chuyên đề"
          size="sm"
          description={`“${deleting.name}” đang được gán cho ${deleting.courses_count} khóa học nên không thể xoá. Bạn có thể ẩn chuyên đề thay thế.`}
          footer={
            <>
              <Button variant="secondary" onClick={() => setDeleting(null)}>
                Đã hiểu
              </Button>
              {deleting.status === "active" ? (
                <Button
                  onClick={() => {
                    setStatus(deleting, false);
                    setDeleting(null);
                  }}
                >
                  Ẩn chuyên đề này
                </Button>
              ) : null}
            </>
          }
        />
      ) : null}
      <ConfirmDialog
        open={deleting !== null && deleting.courses_count === 0}
        onClose={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) setItems(items.filter((x) => x.id !== deleting.id));
          setDeleting(null);
          toast.show({ tone: "success", title: "Đã xoá chuyên đề" });
        }}
        tone="danger"
        title={`Xoá chuyên đề “${deleting?.name ?? ""}”?`}
        description="Hành động này không thể hoàn tác."
        confirmLabel="Xoá"
      />
    </div>
  );
}
