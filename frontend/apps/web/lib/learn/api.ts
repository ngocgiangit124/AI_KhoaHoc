import { getCsrfToken } from "@vitaminvui/api-client";
import { authFetch } from "@/lib/api";
import { env } from "@/env";
import {
  heartbeatResultSchema,
  learnCourseSchema,
  lessonShowSchema,
  playbackInfoSchema,
  type HeartbeatResult,
  type LearnCourse,
  type LessonShow,
  type PlaybackInfo,
} from "./schemas";

/**
 * Mọi hàm ở đây CHỈ gọi từ trình duyệt (Client Component): cookie phiên `vv_session` là host-only của host API và link phát
 * được ký theo IP kết nối — gọi từ SSR/Route Handler thì không có cookie và IP ký sẽ là IP máy chủ Next (security T37 S6).
 */
export async function fetchLearnCourse(courseId: number): Promise<LearnCourse> {
  return learnCourseSchema.parse(await authFetch<unknown>(`/api/v1/learn/courses/${courseId}`));
}

export async function fetchLesson(lessonId: number): Promise<LessonShow> {
  return lessonShowSchema.parse(await authFetch<unknown>(`/api/v1/learn/lessons/${lessonId}`));
}

export async function fetchPlayback(lessonId: number, signal?: AbortSignal): Promise<PlaybackInfo> {
  return playbackInfoSchema.parse(await authFetch<unknown>(`/api/v1/learn/lessons/${lessonId}/playback`, { signal }));
}

/** "Đánh dấu đã học" cho bài link ngoài (không có heartbeat). Không body; 200 cùng shape heartbeat; gọi lại vẫn 200. */
export async function completeLesson(lessonId: number): Promise<HeartbeatResult> {
  return heartbeatResultSchema.parse(await authFetch<unknown>(`/api/v1/learn/lessons/${lessonId}/complete`, { method: "POST" }));
}

/**
 * Lấy sẵn (và cache trong api-client) CSRF token trước khi cần gửi heartbeat lúc đóng trang: trong `pagehide` không kịp
 * `await` một request mới, nhưng token đã cache thì `authFetch` gửi POST `keepalive` ngay trong cùng tick.
 */
export function warmCsrf(): Promise<unknown> {
  return getCsrfToken(env.NEXT_PUBLIC_API_URL).catch(() => undefined);
}

export interface HeartbeatBody {
  /** Số NGUYÊN (backend từ chối số thực với 422). */
  position_seconds: number;
  watched_delta_seconds: number;
}

export async function sendHeartbeat(lessonId: number, body: HeartbeatBody, opts: { keepalive?: boolean } = {}): Promise<HeartbeatResult> {
  return heartbeatResultSchema.parse(
    await authFetch<unknown>(`/api/v1/learn/lessons/${lessonId}/heartbeat`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
      keepalive: opts.keepalive,
    }),
  );
}
