"use client";

import { useEffect } from "react";
import { useToast } from "@vitaminvui/ui/v2";

/**
 * Khi heartbeat trả `completed: true` lần đầu (xem ≥ 90%): toast không chặn video + outline đổi icon ngay.
 * Bản xem trước: bật bằng `?trang-thai=hoan-thanh`.
 */
export function CompletionNotice({ lessonTitle, coursePercent }: { lessonTitle: string; coursePercent: number }) {
  const toast = useToast();
  useEffect(() => {
    toast.show({ tone: "success", title: `Bạn đã hoàn thành ${lessonTitle.split(".")[0]}`, description: `Tiến độ khóa học: ${coursePercent}%.` });
  }, [toast, lessonTitle, coursePercent]);
  return null;
}
