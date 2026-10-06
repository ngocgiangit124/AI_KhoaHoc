import { findCourseById } from "./catalog";
import { circleOutline, genericOutline } from "./course-detail";
import type { LearnCourse, LessonShow, LessonStatus, QuizSummary } from "./types";

const COMPLETED = new Set([301, 302, 303, 304, 305, 306]);
const IN_PROGRESS = new Set([307]);

const quizByLesson: Record<number, QuizSummary[]> = {
  305: [{ id: 501, title: "Trắc nghiệm: tiếp tuyến của đường tròn", time_limit_minutes: 10, question_count: 6 }],
  307: [{ id: 502, title: "Luyện tập tổng hợp chương 2", time_limit_minutes: 15, question_count: 8 }],
};
const quizByChapter: Record<number, QuizSummary[]> = {
  204: [{ id: 503, title: "Đề kiểm tra 45 phút — chương Đường tròn", time_limit_minutes: 45, question_count: 20 }],
};

function statusOf(id: number): LessonStatus {
  if (COMPLETED.has(id)) return "completed";
  if (IN_PROGRESS.has(id)) return "in_progress";
  return "not_started";
}

/** GET /learn/courses/101. */
export const learnCourse: LearnCourse = {
  course: { id: 101, title: "Hình học 9: Đường tròn từ cơ bản đến nâng cao", slug: "hinh-hoc-9-duong-tron" },
  course_percent: 37,
  resume_lesson_id: 307,
  chapters: circleOutline.map((ch) => ({
    id: ch.id,
    title: ch.title,
    position: ch.position,
    quizzes: quizByChapter[ch.id] ?? [],
    lessons: ch.lessons.map((l) => ({
      ...l,
      video_ready: l.id !== 316,
      status: statusOf(l.id),
      quizzes: quizByLesson[l.id] ?? [],
    })),
  })),
};

/**
 * GET /learn/courses/{course} cho mọi khóa trong dữ liệu mẫu. Khóa 101 có dữ liệu chi tiết; khóa khác
 * dùng mục lục chung (khóa 104 coi như đã học xong, còn lại chưa học).
 */
export function getLearnCourse(courseId: number): LearnCourse | null {
  if (courseId === learnCourse.course.id) return learnCourse;
  const base = findCourseById(courseId);
  if (!base) return null;
  const done = courseId === 104;
  const outline = genericOutline(courseId);
  return {
    course: { id: base.id, title: base.title, slug: base.slug },
    course_percent: done ? 100 : 0,
    resume_lesson_id: outline[0]?.lessons[0]?.id ?? null,
    chapters: outline.map((ch) => ({
      ...ch,
      quizzes: [],
      lessons: ch.lessons.map((l) => ({ ...l, video_ready: true, status: done ? ("completed" as const) : ("not_started" as const), quizzes: [] })),
    })),
  };
}

/** GET /learn/lessons/{lesson}. */
export function getLesson(courseId: number, lessonId: number): LessonShow | null {
  const data = getLearnCourse(courseId);
  if (!data) return null;
  const flat = data.chapters.flatMap((ch) => ch.lessons.map((l) => ({ ...l, chapter: ch })));
  const idx = flat.findIndex((l) => l.id === lessonId);
  const cur = flat[idx];
  if (!cur) return null;
  const prev = flat[idx - 1];
  const next = flat[idx + 1];
  return {
    lesson: {
      id: cur.id,
      course_id: data.course.id,
      chapter_id: cur.chapter.id,
      chapter_title: cur.chapter.title,
      title: cur.title,
      position: cur.position,
      is_preview: cur.is_preview,
      duration_seconds: cur.duration_seconds,
      video_ready: cur.video_ready,
    },
    course: data.course,
    can_track: true,
    prev: prev ? { id: prev.id, title: prev.title } : null,
    next: next ? { id: next.id, title: next.title } : null,
    quizzes: cur.quizzes,
    progress:
      cur.status === "not_started"
        ? null
        : {
            status: cur.status,
            watched_seconds: cur.status === "completed" ? cur.duration_seconds : 312,
            last_position_seconds: cur.status === "completed" ? 0 : 312,
            completed_at: cur.status === "completed" ? "2026-10-04T20:15:00+07:00" : null,
          },
  };
}

export function lessonCounts(data: LearnCourse = learnCourse) {
  const all = data.chapters.flatMap((c) => c.lessons);
  return { completed: all.filter((l) => l.status === "completed").length, total: all.length };
}
