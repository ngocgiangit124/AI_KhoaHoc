/** Hợp đồng T21 (api-contract §2.5): QuizResource / QuizQuestionResource của admin-api. */
export interface QuizItem {
  id: number;
  course_id: number;
  chapter_id: number | null;
  lesson_id: number | null;
  parent_type: "chapter" | "lesson";
  /** Chỉ có khi server nạp quan hệ chương/bài (luôn có ở GET danh sách và chi tiết). */
  parent_title?: string | null;
  title: string;
  time_limit_minutes: number | null;
  position: number;
  questions_count?: number;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface QuizOption {
  id: number;
  content: string;
  is_correct: boolean;
  position: number;
}

export interface QuizQuestion {
  id: number;
  quiz_id: number;
  content: string;
  explanation: string | null;
  position: number;
  options: QuizOption[];
  created_at?: string | null;
  updated_at?: string | null;
}

export interface QuizDetail extends QuizItem {
  questions: QuizQuestion[];
}

/** Thân POST/PUT quiz: đúng 1 trong `chapter_id` / `lesson_id`. `time_limit_minutes` bỏ qua khi tính năng giới hạn giờ tắt. */
export interface QuizPayload {
  title: string;
  chapter_id?: number;
  lesson_id?: number;
  time_limit_minutes: number | null;
}

export interface QuestionPayload {
  content: string;
  explanation: string | null;
  options: Array<{ content: string; is_correct: boolean }>;
}

/** Giới hạn từ contract (T21). */
export const QUIZ_LIMITS = { content: 5000, explanation: 5000, option: 1000, questions: 200, title: 255, timeMin: 1, timeMax: 300 } as const;
export const LETTERS = ["A", "B", "C", "D"] as const;
