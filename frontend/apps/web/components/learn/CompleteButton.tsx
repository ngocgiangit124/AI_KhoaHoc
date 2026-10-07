"use client";

import { useState } from "react";
import { ApiError } from "@vitaminvui/api-client";
import { Button, IconCheckCircle } from "@vitaminvui/ui/v2";
import { completeLesson } from "@/lib/learn/api";
import { courseRefFromError } from "@/lib/learn/errors";
import type { HeartbeatResult } from "@/lib/learn/schemas";

/**
 * "Đánh dấu đã học" cho bài link ngoài (iframe không báo được tiến độ → không có heartbeat). Chỉ chủ khóa gọi được;
 * đã học thì hiện chữ "Đã học" (gọi lại API vẫn 200 nhưng không cần). Cập nhật mục lục/tiến độ qua `onDone` như heartbeat.
 */
export function CompleteButton({
  lessonId,
  completed,
  onDone,
  onRevoked,
}: {
  lessonId: number;
  completed: boolean;
  onDone: (result: HeartbeatResult) => void;
  onRevoked?: (course: { slug: string; title: string } | null) => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (completed) {
    return (
      <span className="inline-flex min-h-11 items-center gap-1.5 text-base font-semibold text-success">
        <IconCheckCircle size={20} />
        Đã học
      </span>
    );
  }

  async function click() {
    setBusy(true);
    setError(null);
    try {
      onDone(await completeLesson(lessonId));
    } catch (err) {
      if (err instanceof ApiError && err.status === 403) onRevoked?.(courseRefFromError(err));
      else if (err instanceof ApiError && err.code === "LESSON_COMPLETION_NOT_MANUAL") setError("Bài này tự ghi tiến độ khi xem, không cần đánh dấu.");
      else if (err instanceof ApiError && err.status === 429) setError("Bạn thao tác hơi nhanh. Đợi một chút rồi thử lại.");
      else if (!(err instanceof ApiError && err.status === 401)) setError("Chưa lưu được. Hãy thử lại.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="flex flex-col gap-1">
      <Button onClick={click} loading={busy}>
        Đánh dấu đã học
      </Button>
      {error ? (
        <p role="alert" className="text-sm text-danger">
          {error}
        </p>
      ) : null}
    </div>
  );
}
