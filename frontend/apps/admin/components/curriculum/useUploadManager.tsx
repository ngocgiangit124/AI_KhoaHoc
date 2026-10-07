"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { startVideoUpload } from "@/lib/curriculum/api";
import { curriculumError } from "@/lib/curriculum/errors";
import { createTusUpload, type TusFailure, type TusHandle } from "@/lib/curriculum/tusUpload";

export const NETWORK_LOST_MESSAGE = "Mất kết nối mạng. Sẽ tự tiếp tục từ chỗ dừng khi có mạng lại.";

export type UploadState =
  | { phase: "starting"; filename: string; size: number }
  | { phase: "uploading"; filename: string; size: number; sent: number; percent: number; etaSeconds: number | null }
  /** Hết lần tự thử lại (mất mạng): chờ `online` hoặc người dùng bấm "Tải tiếp". */
  | { phase: "paused"; filename: string; size: number; sent: number; percent: number; message: string }
  | { phase: "failed"; filename: string; size: number; message: string };

export interface UploadManager {
  uploads: Readonly<Record<number, UploadState>>;
  hasActive: boolean;
  start: (lessonId: number, file: File) => void;
  cancel: (lessonId: number) => void;
  resume: (lessonId: number) => void;
  dismiss: (lessonId: number) => void;
}

interface Entry {
  courseId: number;
  handle: TusHandle | null;
  file: File;
  startedAt: number;
  startBytes: number | null;
}

export type UploadFinished = { courseId: number; lessonId: number; outcome: "uploaded" | "stopped" };

interface UploadCore {
  uploads: Readonly<Record<number, UploadState>>;
  hasActive: boolean;
  start: (courseId: number, lessonId: number, file: File) => void;
  cancel: (lessonId: number) => void;
  resume: (lessonId: number) => void;
  dismiss: (lessonId: number) => void;
  subscribe: (listener: (e: UploadFinished) => void) => () => void;
}

/**
 * Lõi quản lý các lượt tải video đang chạy theo bài (id bài là duy nhất toàn hệ thống). Đặt ở `UploadProvider` trong layout
 * `/quan-tri` để lượt tải sống qua điều hướng trong ứng dụng (đổi bài, đổi tab, sang menu khác, nút Back).
 */
function useUploadCore(): UploadCore {
  const [uploads, setUploads] = useState<Record<number, UploadState>>({});
  const entries = useRef(new Map<number, Entry>());
  const listeners = useRef(new Set<(e: UploadFinished) => void>());
  const finishedRef = useRef((courseId: number, lessonId: number, outcome: UploadFinished["outcome"]) => {
    for (const l of listeners.current) l({ courseId, lessonId, outcome });
  });
  const subscribe = useCallback((l: (e: UploadFinished) => void) => {
    listeners.current.add(l);
    return () => void listeners.current.delete(l);
  }, []);

  const patch = useCallback((lessonId: number, next: UploadState | null) => {
    setUploads((prev) => {
      if (next === null) {
        if (!(lessonId in prev)) return prev;
        const { [lessonId]: _gone, ...rest } = prev;
        void _gone;
        return rest;
      }
      return { ...prev, [lessonId]: next };
    });
  }, []);

  const drop = useCallback(
    (lessonId: number) => {
      entries.current.delete(lessonId);
      patch(lessonId, null);
    },
    [patch],
  );

  const handleFailure = useCallback(
    (courseId: number, lessonId: number, file: File, failure: TusFailure, sent: number) => {
      if (failure.retryable) {
        const percent = file.size > 0 ? Math.floor((sent / file.size) * 100) : 0;
        patch(lessonId, { phase: "paused", filename: file.name, size: file.size, sent, percent, message: NETWORK_LOST_MESSAGE });
        return;
      }
      entries.current.delete(lessonId);
      patch(lessonId, {
        phase: "failed",
        filename: file.name,
        size: file.size,
        message: failure.message ?? "Tải video lên thất bại. Hãy thử lại hoặc chọn tệp khác.",
      });
      finishedRef.current(courseId, lessonId, "stopped");
    },
    [patch],
  );

  const start = useCallback(
    (courseId: number, lessonId: number, file: File) => {
      if (entries.current.has(lessonId)) return;
      const entry: Entry = { courseId, handle: null, file, startedAt: Date.now(), startBytes: null };
      entries.current.set(lessonId, entry);
      patch(lessonId, { phase: "starting", filename: file.name, size: file.size });
      let lastSent = 0;
      startVideoUpload(courseId, lessonId, { name: file.name, size: file.size })
        .then((session) => {
          if (entries.current.get(lessonId) !== entry) return; // đã huỷ trong lúc xin phiên
          entry.handle = createTusUpload(file, session.upload, {
            onProgress: (sent, total) => {
              if (entries.current.get(lessonId) !== entry) return;
              lastSent = sent;
              if (entry.startBytes === null) {
                entry.startBytes = sent;
                entry.startedAt = Date.now();
              }
              const elapsed = (Date.now() - entry.startedAt) / 1000;
              const speed = elapsed > 1 ? (sent - entry.startBytes) / elapsed : 0;
              const etaSeconds = speed > 0 ? (total - sent) / speed : null;
              patch(lessonId, { phase: "uploading", filename: file.name, size: file.size, sent, percent: total > 0 ? Math.floor((sent / total) * 100) : 0, etaSeconds });
            },
            onSuccess: () => {
              if (entries.current.get(lessonId) !== entry) return;
              drop(lessonId);
              finishedRef.current(courseId, lessonId, "uploaded");
            },
            onError: (failure) => {
              if (entries.current.get(lessonId) !== entry) return;
              handleFailure(courseId, lessonId, file, failure, lastSent);
            },
          });
        })
        .catch((err: unknown) => {
          if (entries.current.get(lessonId) !== entry) return;
          entries.current.delete(lessonId);
          patch(lessonId, { phase: "failed", filename: file.name, size: file.size, message: curriculumError(err) });
        });
    },
    [patch, drop, handleFailure],
  );

  const cancel = useCallback(
    (lessonId: number) => {
      const entry = entries.current.get(lessonId);
      if (!entry) return;
      drop(lessonId);
      void (entry.handle?.abort() ?? Promise.resolve()).finally(() => finishedRef.current(entry.courseId, lessonId, "stopped"));
    },
    [drop],
  );

  const resume = useCallback(
    (lessonId: number) => {
      const entry = entries.current.get(lessonId);
      if (!entry?.handle) return;
      entry.startBytes = null;
      patch(lessonId, { phase: "uploading", filename: entry.file.name, size: entry.file.size, sent: 0, percent: 0, etaSeconds: null });
      entry.handle.resume();
    },
    [patch],
  );

  const dismiss = useCallback((lessonId: number) => drop(lessonId), [drop]);

  const pausedCount = Object.values(uploads).filter((u) => u.phase === "paused").length;
  const hasActive = Object.values(uploads).some((u) => u.phase === "starting" || u.phase === "uploading" || u.phase === "paused");

  const uploadsRef = useRef(uploads);
  const resumeRef = useRef(resume);
  useEffect(() => {
    uploadsRef.current = uploads;
    resumeRef.current = resume;
  });

  // Có mạng trở lại → tự tiếp tục các lượt đang dừng (tus gửi HEAD lấy offset rồi PATCH tiếp).
  useEffect(() => {
    if (pausedCount === 0) return;
    const onOnline = () => {
      for (const [id, u] of Object.entries(uploadsRef.current)) if (u.phase === "paused") resumeRef.current(Number(id));
    };
    window.addEventListener("online", onOnline);
    return () => window.removeEventListener("online", onOnline);
  }, [pausedCount]);

  // Đang tải mà đóng tab/tải lại trang: hỏi lại (tải dở sẽ mất).
  useEffect(() => {
    if (!hasActive) return;
    const handler = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = "";
    };
    window.addEventListener("beforeunload", handler);
    return () => window.removeEventListener("beforeunload", handler);
  }, [hasActive]);

  // Provider bị gỡ (rời khu quản trị/đăng xuất): dừng mọi lượt tải còn chạy (server dọn asset dở bằng videos:prune-orphans).
  useEffect(() => {
    const map = entries.current;
    return () => {
      for (const entry of map.values()) void entry.handle?.abort();
      map.clear();
    };
  }, []);

  return useMemo(
    () => ({ uploads, hasActive, start, cancel, resume, dismiss, subscribe }),
    [uploads, hasActive, start, cancel, resume, dismiss, subscribe],
  );
}

const UploadContext = createContext<UploadCore | null>(null);

/** Đặt một lần ở layout `/quan-tri`: lượt tải video sống qua mọi điều hướng trong ứng dụng. */
export function UploadProvider({ children }: { children: ReactNode }) {
  const core = useUploadCore();
  return <UploadContext.Provider value={core}>{children}</UploadContext.Provider>;
}

/**
 * Giao diện của một khóa học: `start` gắn sẵn `courseId`; `onFinished(lessonId, outcome)` chỉ nhận sự kiện của khóa này
 * (tải xong hoặc dừng — màn hình tải lại cây để thấy trạng thái server).
 */
export function useUploadManager(courseId: number, onFinished: (lessonId: number, outcome: "uploaded" | "stopped") => void): UploadManager {
  const core = useContext(UploadContext);
  if (!core) throw new Error("useUploadManager phải nằm trong <UploadProvider>.");
  const { subscribe, start: coreStart } = core;
  const ref = useRef(onFinished);
  useEffect(() => {
    ref.current = onFinished;
  });
  useEffect(
    () =>
      subscribe((e) => {
        if (e.courseId === courseId) ref.current(e.lessonId, e.outcome);
      }),
    [subscribe, courseId],
  );
  const start = useCallback((lessonId: number, file: File) => coreStart(courseId, lessonId, file), [coreStart, courseId]);
  return useMemo(
    () => ({ uploads: core.uploads, hasActive: core.hasActive, start, cancel: core.cancel, resume: core.resume, dismiss: core.dismiss }),
    [core.uploads, core.hasActive, start, core.cancel, core.resume, core.dismiss],
  );
}
