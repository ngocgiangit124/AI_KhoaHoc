"use client";

import { useState } from "react";
import { Badge, Dialog, IconPlayCircle, formatClock } from "@vitaminvui/ui/v2";
import { VideoFrame } from "@/components/v2/learn/VideoFrame";

/** Dòng bài "Học thử": bấm mở hộp thoại phát video (GET /preview/lessons/{lesson}/playback, không cần đăng nhập). */
export function PreviewLessonButton({ title, durationSeconds }: { title: string; durationSeconds: number }) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        aria-haspopup="dialog"
        className="focus-ring flex min-h-12 w-full items-center gap-3 rounded-control px-3 py-2 text-left hover:bg-primary-soft"
      >
        <IconPlayCircle className="text-primary" />
        <span className="flex-1 text-base text-ink">{title}</span>
        <Badge tone="primary" size="sm">
          Học thử
        </Badge>
        <span className="num w-12 text-right text-sm text-ink-soft">{formatClock(durationSeconds)}</span>
      </button>
      <Dialog open={open} onClose={() => setOpen(false)} title={title} description="Bài học thử — xem miễn phí, không cần đăng nhập." size="lg">
        <div className="-mx-5 sm:mx-0">
          <VideoFrame title={title} durationSeconds={durationSeconds} />
        </div>
      </Dialog>
    </>
  );
}
