import { authFetch } from "@/lib/api";
import { unwrapOne } from "@/lib/courses/api";
import type { Chapter, CurriculumResponse, Lesson, LessonPayload, LessonVideoInfo, VideoUploadSession } from "./types";

const base = (courseId: number) => `/api/v1/admin/courses/${courseId}`;
const JSON_HEADERS = { "Content-Type": "application/json" };

const json = (method: string, body: unknown) => ({ method, headers: JSON_HEADERS, body: JSON.stringify(body) });

export function getCurriculum(courseId: number, signal?: AbortSignal): Promise<CurriculumResponse> {
  return authFetch<CurriculumResponse>(`${base(courseId)}/chapters`, { signal });
}

export async function createChapter(courseId: number, title: string): Promise<Chapter> {
  return unwrapOne(await authFetch<Chapter | { data: Chapter }>(`${base(courseId)}/chapters`, json("POST", { title })));
}

export async function renameChapter(courseId: number, chapterId: number, title: string): Promise<Chapter> {
  return unwrapOne(await authFetch<Chapter | { data: Chapter }>(`${base(courseId)}/chapters/${chapterId}`, json("PUT", { title })));
}

export async function deleteChapter(courseId: number, chapterId: number): Promise<void> {
  await authFetch<void>(`${base(courseId)}/chapters/${chapterId}`, { method: "DELETE" });
}

export async function createLesson(courseId: number, chapterId: number, payload: LessonPayload): Promise<Lesson> {
  return unwrapOne(await authFetch<Lesson | { data: Lesson }>(`${base(courseId)}/chapters/${chapterId}/lessons`, json("POST", payload)));
}

export async function updateLesson(courseId: number, chapterId: number, lessonId: number, payload: LessonPayload): Promise<Lesson> {
  return unwrapOne(await authFetch<Lesson | { data: Lesson }>(`${base(courseId)}/chapters/${chapterId}/lessons/${lessonId}`, json("PUT", payload)));
}

export async function deleteLesson(courseId: number, chapterId: number, lessonId: number): Promise<void> {
  await authFetch<void>(`${base(courseId)}/chapters/${chapterId}/lessons/${lessonId}`, { method: "DELETE" });
}

/** `PUT .../curriculum/order`: thân là MẢNG gốc `[{chapter_id, lesson_ids[]}]`, phải đủ đúng tập ID hiện có. */
export function saveCurriculumOrder(courseId: number, order: Array<{ chapter_id: number; lesson_ids: number[] }>): Promise<CurriculumResponse> {
  return authFetch<CurriculumResponse>(`${base(courseId)}/curriculum/order`, json("PUT", order));
}

export function startVideoUpload(courseId: number, lessonId: number, file: { name: string; size: number }): Promise<VideoUploadSession> {
  return authFetch<VideoUploadSession>(`${base(courseId)}/lessons/${lessonId}/video-uploads`, json("POST", { filename: file.name, size: file.size }));
}

export function getLessonVideo(courseId: number, lessonId: number, signal?: AbortSignal): Promise<LessonVideoInfo> {
  return authFetch<LessonVideoInfo>(`${base(courseId)}/lessons/${lessonId}/video`, { signal });
}
