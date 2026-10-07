"use client";

import { useId, useState, type FormEvent } from "react";
import { Alert, Button, Dialog, Field, TextInput } from "@vitaminvui/ui/v2";
import { curriculumError, lessonFieldErrors } from "@/lib/curriculum/errors";

export const NAME_MAX = 255;

/**
 * Hộp thoại nhập một tên (thêm/sửa chương, thêm bài). `onSubmit` ném lỗi khi API từ chối: 422 hiện dưới ô, lỗi khác hiện banner.
 * Dựng mới mỗi lần mở (parent đặt `key`) nên không cần reset state.
 */
export function NameDialog({
  title,
  label,
  submitLabel,
  initial = "",
  onSubmit,
  onClose,
}: {
  title: string;
  label: string;
  submitLabel: string;
  initial?: string;
  onSubmit: (name: string) => Promise<void>;
  onClose: () => void;
}) {
  const formId = useId();
  const [value, setValue] = useState(initial);
  const [busy, setBusy] = useState(false);
  const [fieldError, setFieldError] = useState<string | undefined>();
  const [banner, setBanner] = useState<string | null>(null);

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (busy) return;
    const name = value.trim();
    if (!name) {
      setFieldError(`${label} không được để trống.`);
      return;
    }
    setBusy(true);
    setFieldError(undefined);
    setBanner(null);
    try {
      await onSubmit(name);
    } catch (err) {
      const fields = lessonFieldErrors(err);
      if (fields.title) setFieldError(fields.title);
      else setBanner(curriculumError(err));
      setBusy(false);
    }
  }

  return (
    <Dialog
      open
      onClose={onClose}
      title={title}
      size="sm"
      dismissible={!busy}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Huỷ
          </Button>
          <Button type="submit" form={formId} loading={busy} loadingText="Đang lưu…">
            {submitLabel}
          </Button>
        </>
      }
    >
      <form id={formId} onSubmit={submit} noValidate className="flex flex-col gap-3">
        {banner ? <Alert tone="danger">{banner}</Alert> : null}
        <Field label={label} required error={fieldError}>
          <TextInput autoFocus value={value} maxLength={NAME_MAX} onChange={(e) => setValue(e.target.value)} />
        </Field>
      </form>
    </Dialog>
  );
}
