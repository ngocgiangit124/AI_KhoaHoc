"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { Alert, Button, Dialog, Field, Select, TextInput } from "@vitaminvui/ui/v2";
import { STAFF_ROLES, STAFF_ROLE_LABELS, type StaffRole } from "@/lib/auth/types";
import { createStaff } from "@/lib/staff/api";
import { classifyCreateError, type CreateErrors, type CreateField } from "@/lib/staff/errors";
import { CREATE_IDS, CREATE_LABELS, EMAIL_MAX_LENGTH, EMPTY_CREATE, NAME_MAX_LENGTH, validateCreate, type CreateValues } from "@/lib/staff/form";
import type { StaffWithPassword } from "@/lib/staff/types";

export interface StaffCreateDialogProps {
  /** Nên ổn định (useCallback). */
  onClose: () => void;
  onCreated: (created: StaffWithPassword) => void;
  /** Tải lại danh sách (phục hồi khi không chắc tài khoản đã được tạo chưa). */
  onReload?: () => void;
}

const ORDER: readonly CreateField[] = ["name", "email", "role"];

/** Hộp thoại "Tạo tài khoản staff mới" (US-016 §2.5). Chỉ 3 vai trò staff (không có Học sinh). 422 → dưới đúng ô + hộp tóm tắt; giữ dữ liệu đã nhập. */
export function StaffCreateDialog({ onClose, onCreated, onReload }: StaffCreateDialogProps) {
  const [values, setValues] = useState<CreateValues>(EMPTY_CREATE);
  const [errors, setErrors] = useState<CreateErrors>({});
  const [banner, setBanner] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const [tick, setTick] = useState(0);
  const [uncertain, setUncertain] = useState(false);
  const pendingRef = useRef(false);
  const summaryRef = useRef<HTMLDivElement>(null);
  const nameRef = useRef<HTMLInputElement>(null);
  const formId = "staff-create-form";

  useEffect(() => {
    const t = setTimeout(() => nameRef.current?.focus(), 0);
    return () => clearTimeout(t);
  }, []);
  useEffect(() => {
    if (tick > 0) summaryRef.current?.focus();
  }, [tick]);

  const closeRef = useRef(onClose);
  useEffect(() => {
    closeRef.current = onClose;
  }, [onClose]);
  const [stableClose] = useState(() => () => {
    if (!pendingRef.current) closeRef.current();
  });

  const set = <K extends keyof CreateValues>(key: K, v: CreateValues[K]) => {
    setValues((cur) => ({ ...cur, [key]: v }));
    setErrors((e) => (e[key] ? { ...e, [key]: undefined } : e));
  };

  function fail(fields: CreateErrors, text: string | null) {
    setErrors(fields);
    setBanner(text);
    setTick((n) => n + 1);
  }

  function jump(k: CreateField) {
    const el = document.getElementById(CREATE_IDS[k]);
    el?.scrollIntoView?.({ block: "center" });
    el?.focus();
  }

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (pendingRef.current) return;
    const invalid = validateCreate(values);
    if (Object.keys(invalid).length > 0) {
      fail(invalid, null);
      return;
    }
    pendingRef.current = true;
    setPending(true);
    setBanner(null);
    setErrors({});
    setUncertain(false);
    try {
      const created = await createStaff({ name: values.name.trim(), email: values.email.trim(), role: values.role as StaffRole });
      onCreated(created);
    } catch (err) {
      const maybeCreated = err instanceof NetworkError || (err instanceof ApiError && err.status >= 500);
      const failure = classifyCreateError(err);
      setUncertain(maybeCreated);
      fail(failure.fields, maybeCreated ? `${failure.banner ?? ""} Có thể tài khoản đã được tạo. Hãy tải lại danh sách; nếu thấy tài khoản, dùng Đặt lại mật khẩu.`.trim() : failure.banner);
    } finally {
      pendingRef.current = false;
      setPending(false);
    }
  }

  const list = ORDER.filter((k) => errors[k]).map((k) => [k, errors[k] as string] as const);

  return (
    <Dialog
      open
      size="md"
      title="Tạo tài khoản staff mới"
      description="Hệ thống sinh mật khẩu khởi tạo và chỉ hiển thị một lần sau khi tạo."
      onClose={stableClose}
      dismissible={!pending}
      footer={
        <>
          <Button type="button" variant="secondary" className="max-sm:h-11" onClick={stableClose} disabled={pending}>
            Huỷ
          </Button>
          <Button type="submit" form={formId} className="max-sm:h-11" loading={pending} loadingText="Đang tạo…">
            Tạo tài khoản
          </Button>
        </>
      }
    >
      <form id={formId} onSubmit={(e) => void onSubmit(e)} noValidate aria-busy={pending} className="flex flex-col gap-4">
        {list.length > 0 || banner ? (
          <div ref={summaryRef} tabIndex={-1} className="focus-ring rounded-card">
            <Alert tone="danger" title={list.length > 0 ? `Chưa tạo được — còn ${list.length} chỗ cần sửa` : "Chưa tạo được tài khoản"}>
              {banner ? <p>{banner}</p> : null}
              {uncertain && onReload ? (
                <Button type="button" size="sm" variant="secondary" className="mt-2 max-sm:h-11" onClick={onReload}>
                  Tải lại danh sách
                </Button>
              ) : null}
              {list.length > 0 ? (
                <ul className="list-disc pl-5">
                  {list.map(([k, msg]) => (
                    <li key={k}>
                      <a
                        href={`#${CREATE_IDS[k]}`}
                        onClick={(ev) => {
                          ev.preventDefault();
                          jump(k);
                        }}
                        className="inline-flex min-h-11 items-center font-semibold text-danger underline underline-offset-2 sm:min-h-0"
                      >
                        {CREATE_LABELS[k]}
                      </a>
                      : {msg}
                    </li>
                  ))}
                </ul>
              ) : null}
            </Alert>
          </div>
        ) : null}
        <Field label="Họ và tên" required error={errors.name} id={CREATE_IDS.name}>
          <TextInput ref={nameRef} name="name" value={values.name} maxLength={NAME_MAX_LENGTH} autoComplete="off" onChange={(e) => set("name", e.target.value)} />
        </Field>
        <Field label="Email" required error={errors.email} id={CREATE_IDS.email}>
          <TextInput name="email" type="email" value={values.email} maxLength={EMAIL_MAX_LENGTH} autoComplete="off" onChange={(e) => set("email", e.target.value)} />
        </Field>
        <Field label="Vai trò" required error={errors.role} id={CREATE_IDS.role}>
          <Select name="role" value={values.role} onChange={(e) => set("role", e.target.value as CreateValues["role"])}>
            <option value="">Chọn vai trò</option>
            {STAFF_ROLES.map((r) => (
              <option key={r} value={r}>
                {STAFF_ROLE_LABELS[r]}
              </option>
            ))}
          </Select>
        </Field>
      </form>
    </Dialog>
  );
}
