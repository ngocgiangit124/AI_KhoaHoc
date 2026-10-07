"use client";

import { useCallback, useRef, useState, type ReactNode } from "react";
import { Button, ConfirmDialog, Dialog, useToast } from "@vitaminvui/ui/v2";
import { deleteCourse, publishCourse, unpublishCourse } from "@/lib/courses/api";
import { NOT_PUBLISHABLE_MESSAGE, courseActionError, isHasEnrollments, isNotFound, isNotPublishable, isStaleState } from "@/lib/courses/errors";
import type { CourseStatus } from "@/lib/courses/types";

export interface ActionTarget {
  id: number;
  title: string;
  status: CourseStatus;
}

type Dialog =
  | { kind: "unpublish"; target: ActionTarget }
  | { kind: "delete"; target: ActionTarget }
  | { kind: "not-publishable"; target: ActionTarget }
  | { kind: "has-enrollments"; target: ActionTarget };

export interface UseCourseActionsOptions {
  /** Thao tác thành công (xuất bản/ngừng bán/xoá): màn hình tải lại dữ liệu hoặc rời trang. */
  onDone: (kind: "publish" | "unpublish" | "delete", target: ActionTarget) => void;
  /** Dữ liệu trên màn hình đã cũ (404/409 trạng thái/409 có học sinh): tải lại. */
  onStale: () => void;
}

/**
 * Xuất bản / ngừng bán / xoá khóa học (US-009 AC2-AC5), dùng chung danh sách và trang sửa. Mã lỗi theo api-contract:
 * 422 COURSE_NOT_PUBLISHABLE (AC3), 409 COURSE_HAS_ENROLLMENTS (AC4), 409 ALREADY_PROCESSED/INVALID_COURSE_STATE, 404.
 */
export function useCourseActions({ onDone, onStale }: UseCourseActionsOptions): {
  busyIds: ReadonlySet<number>;
  publish: (t: ActionTarget) => void;
  askUnpublish: (t: ActionTarget) => void;
  askDelete: (t: ActionTarget) => void;
  dialogs: ReactNode;
} {
  const toast = useToast();
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const [busyIds, setBusyIds] = useState<ReadonlySet<number>>(new Set());
  const openerRef = useRef<HTMLElement | null>(null);
  const dialogBusyRef = useRef(false);

  const remember = () => {
    openerRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
  };
  const closeDialog = useCallback(() => {
    dialogBusyRef.current = false;
    setDialog(null);
    const el = openerRef.current;
    setTimeout(() => {
      if (el?.isConnected) el.focus();
    }, 0);
  }, []);
  const closeIfIdle = useCallback(() => {
    if (!dialogBusyRef.current) closeDialog();
  }, [closeDialog]);

  const withBusy = useCallback(async (id: number, fn: () => Promise<void>) => {
    setBusyIds((s) => new Set(s).add(id));
    try {
      await fn();
    } finally {
      setBusyIds((s) => {
        const n = new Set(s);
        n.delete(id);
        return n;
      });
    }
  }, []);

  /** Lỗi chung cho cả 3 thao tác; trả `true` nếu đã xử lý bằng hộp thoại riêng. */
  const handleError = useCallback(
    (err: unknown, target: ActionTarget): void => {
      if (isNotPublishable(err)) {
        dialogBusyRef.current = false;
        setDialog({ kind: "not-publishable", target });
        return;
      }
      if (isHasEnrollments(err)) {
        dialogBusyRef.current = false;
        setDialog({ kind: "has-enrollments", target });
        onStale();
        return;
      }
      toast.show({ tone: "danger", title: courseActionError(err) });
      if (isNotFound(err) || isStaleState(err)) onStale();
      closeDialog();
    },
    [toast, onStale, closeDialog],
  );

  const publish = useCallback(
    (target: ActionTarget) => {
      remember();
      void withBusy(target.id, async () => {
        try {
          await publishCourse(target.id);
          toast.show({ tone: "success", title: "Đã xuất bản khóa học" });
          onDone("publish", target);
        } catch (err) {
          handleError(err, target);
        }
      });
    },
    [withBusy, toast, onDone, handleError],
  );

  const doUnpublish = useCallback(
    async (target: ActionTarget) => {
      dialogBusyRef.current = true;
      await withBusy(target.id, async () => {
        try {
          await unpublishCourse(target.id);
          toast.show({ tone: "success", title: "Đã ngừng bán khóa học" });
          closeDialog();
          onDone("unpublish", target);
        } catch (err) {
          handleError(err, target);
        }
      });
    },
    [withBusy, toast, closeDialog, onDone, handleError],
  );

  const askUnpublish = useCallback((target: ActionTarget) => {
    remember();
    setDialog({ kind: "unpublish", target });
  }, []);
  const askDelete = useCallback((target: ActionTarget) => {
    remember();
    setDialog({ kind: "delete", target });
  }, []);

  let dialogs: ReactNode = null;
  if (dialog?.kind === "unpublish") {
    const target = dialog.target;
    dialogs = (
      <ConfirmDialog
        open
        tone="danger"
        title="Ngừng bán khóa học"
        description={`Ngừng bán '${target.title}'? Khóa học sẽ ẩn khỏi danh mục công khai, nhưng học sinh đã mua vẫn giữ quyền truy cập.`}
        confirmLabel="Ngừng bán"
        loading={busyIds.has(target.id)}
        loadingText="Đang xử lý…"
        onClose={closeIfIdle}
        onConfirm={() => void doUnpublish(target)}
      />
    );
  } else if (dialog?.kind === "delete") {
    const target = dialog.target;
    dialogs = (
      <ConfirmDialog
        open
        tone="danger"
        title="Xoá khóa học"
        description={`Xoá khóa học '${target.title}'? Hành động này không thể hoàn tác.`}
        confirmLabel="Xoá"
        loading={busyIds.has(target.id)}
        loadingText="Đang xoá…"
        onClose={closeIfIdle}
        onConfirm={() => {
          dialogBusyRef.current = true;
          void withBusy(target.id, async () => {
            try {
              await deleteCourse(target.id);
              toast.show({ tone: "success", title: "Đã xoá khóa học" });
              closeDialog();
              onDone("delete", target);
            } catch (err) {
              handleError(err, target);
            }
          });
        }}
      />
    );
  } else if (dialog?.kind === "not-publishable") {
    dialogs = (
      <Dialog open title="Chưa thể xuất bản" size="sm" onClose={closeDialog} footer={<Button onClick={closeDialog}>Đã hiểu</Button>}>
        <p className="text-base text-ink">{NOT_PUBLISHABLE_MESSAGE}</p>
      </Dialog>
    );
  } else if (dialog?.kind === "has-enrollments") {
    const target = dialog.target;
    dialogs = (
      <Dialog
        open
        title="Không thể xoá khóa học"
        size="sm"
        onClose={closeIfIdle}
        footer={
          <>
            <Button variant="secondary" onClick={closeIfIdle}>
              Đóng
            </Button>
            {target.status === "published" ? (
              <Button variant="danger" loading={busyIds.has(target.id)} onClick={() => void doUnpublish(target)}>
                Ngừng bán
              </Button>
            ) : null}
          </>
        }
      >
        <p className="text-base text-ink">
          Không thể xoá &lsquo;{target.title}&rsquo; vì đã có học sinh đăng ký. Hãy chuyển sang Ngừng bán: khóa học ẩn khỏi danh mục công khai nhưng học sinh đã mua vẫn giữ quyền truy cập.
        </p>
      </Dialog>
    );
  }

  return { busyIds, publish, askUnpublish, askDelete, dialogs };
}
