"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { Alert, Button, Dialog, Field, TextInput } from "@vitaminvui/ui/v2";
import { createSubject, renameSubject } from "@/lib/subjects/api";
import { classifySubjectFormError, isSubjectGone } from "@/lib/subjects/errors";
import { NAME_MAX_LENGTH, normalizeSubjectName, validateSubjectName } from "@/lib/subjects/query";
import type { Subject } from "@/lib/subjects/types";

export interface SubjectFormModalProps {
  /** Có → sửa tên; không → tạo mới. */
  subject?: Subject;
  /** Nên ổn định (useCallback). */
  onClose: () => void;
  onSaved: () => void;
  /** Chuyên đề không còn tồn tại (404 khi sửa): màn hình tải lại danh sách để dòng cũ biến mất. */
  onGone?: () => void;
}

/** Hộp thoại dùng chung tạo & đổi tên chuyên đề (design US-011 §2.2). Lỗi 422 hiện dưới ô tên, giữ nguyên dữ liệu đã nhập. */
export function SubjectFormModal({ subject, onClose, onSaved, onGone }: SubjectFormModalProps) {
  const [name, setName] = useState(subject?.name ?? "");
  const [nameError, setNameError] = useState<string | null>(null);
  const [banner, setBanner] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const pendingRef = useRef(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const formId = "subject-form";
  const isEdit = Boolean(subject);

  // `<dialog>` tự focus phần tử đầu tiên khi mở → focus ô nhập sau đó.
  useEffect(() => {
    const t = setTimeout(() => inputRef.current?.focus(), 0);
    return () => clearTimeout(t);
  }, []);

  // Không cho Esc/overlay đóng khi đang gửi (tránh mất kết quả).
  const guardedClose = useRef(onClose);
  useEffect(() => {
    guardedClose.current = onClose;
  }, [onClose]);
  const [stableClose] = useState(() => () => {
    if (!pendingRef.current) guardedClose.current();
  });

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    if (pendingRef.current) return;
    const invalid = validateSubjectName(name);
    if (invalid) {
      setNameError(invalid);
      setBanner(null);
      inputRef.current?.focus();
      return;
    }
    const clean = normalizeSubjectName(name);
    pendingRef.current = true;
    setPending(true);
    setNameError(null);
    setBanner(null);
    try {
      if (subject) await renameSubject(subject.id, clean);
      else await createSubject(clean);
      onSaved();
    } catch (err) {
      const failure = classifySubjectFormError(err);
      setNameError(failure.nameError);
      setBanner(failure.banner);
      if (isSubjectGone(err)) onGone?.();
      if (failure.nameError) inputRef.current?.focus();
    } finally {
      pendingRef.current = false;
      setPending(false);
    }
  }

  return (
    <Dialog
      open
      size="sm"
      title={isEdit ? "Sửa chuyên đề" : "Tạo chuyên đề"}
      onClose={stableClose}
      dismissible={!pending}
      footer={
        <>
          <Button type="button" variant="secondary" onClick={stableClose} disabled={pending}>
            Huỷ
          </Button>
          <Button type="submit" form={formId} loading={pending} loadingText="Đang lưu…">
            Lưu
          </Button>
        </>
      }
    >
      <form id={formId} onSubmit={(e) => void onSubmit(e)} noValidate className="flex flex-col gap-4">
        {banner ? <Alert tone="danger">{banner}</Alert> : null}
        <Field
          label="Tên chuyên đề"
          required
          error={nameError ?? undefined}
          hint={
            subject ? (
              <>
                Đường dẫn (slug) giữ nguyên khi đổi tên: <span className="font-mono">{subject.slug}</span>
              </>
            ) : (
              "Đường dẫn (slug) được tạo tự động từ tên."
            )
          }
        >
          <TextInput
            ref={inputRef}
            name="name"
            value={name}
            // Chừa dư để dán chuỗi hơi dài vẫn thấy lỗi "tối đa 100 ký tự" rõ ràng thay vì bị cắt âm thầm.
            maxLength={NAME_MAX_LENGTH + 20}
            autoComplete="off"
            onChange={(e) => {
              setName(e.target.value);
              if (nameError) setNameError(null);
            }}
          />
        </Field>
      </form>
    </Dialog>
  );
}
