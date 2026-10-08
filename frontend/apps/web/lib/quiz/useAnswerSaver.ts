"use client";

import { useEffect, useState, useSyncExternalStore } from "react";
import { AnswerSaver, type SaverSnapshot } from "./autosave";
import { putAnswer } from "./api";
import { classifySaveError } from "./errors";
import { warmCsrf } from "@/lib/learn/api";

/**
 * Gắn `AnswerSaver` vào vòng đời trang làm bài: gửi lại khi có mạng, flush `keepalive` khi ẩn tab/rời trang, hỏi lại khi đóng tab
 * lúc còn đáp án chưa lưu. `paused`: phiên đã mất (SessionEndedGate) → ngừng gửi.
 */
export function useAnswerSaver(attemptId: number, initial: Readonly<Record<string, number>>, paused: boolean): [AnswerSaver, SaverSnapshot] {
  const [saver] = useState(
    () =>
      new AnswerSaver(
        { put: (q, o, opts) => putAnswer(attemptId, q, o, opts), classify: classifySaveError },
        initial,
      ),
  );
  const snap = useSyncExternalStore(saver.subscribe, saver.getSnapshot, saver.getSnapshot);

  useEffect(() => {
    // Lấy sẵn CSRF token: trong `pagehide` không kịp await một request mới.
    void warmCsrf();
    const onOnline = () => saver.retryNow();
    const onHide = () => saver.flushKeepalive();
    const onVisibility = () => {
      if (document.visibilityState === "hidden") saver.flushKeepalive();
    };
    const onBeforeUnload = (e: BeforeUnloadEvent) => {
      if (saver.getSnapshot().unsaved > 0) {
        e.preventDefault();
        e.returnValue = "";
      }
    };
    window.addEventListener("online", onOnline);
    window.addEventListener("pagehide", onHide);
    window.addEventListener("beforeunload", onBeforeUnload);
    document.addEventListener("visibilitychange", onVisibility);
    return () => {
      window.removeEventListener("online", onOnline);
      window.removeEventListener("pagehide", onHide);
      window.removeEventListener("beforeunload", onBeforeUnload);
      document.removeEventListener("visibilitychange", onVisibility);
      // Điều hướng mềm (nút Thoát): trang vẫn sống nên gửi request THƯỜNG (keepalive + CORS preflight không đáng tin ở mọi trình duyệt).
      void saver.flushNow();
      saver.dispose();
    };
  }, [saver]);

  useEffect(() => {
    if (paused) saver.pause();
  }, [paused, saver]);

  return [saver, snap];
}
