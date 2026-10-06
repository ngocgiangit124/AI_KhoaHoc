import type { AttemptInProgress, AttemptResult, QuizOption } from "./types";

/**
 * Lượt làm quiz 502 "Luyện tập tổng hợp chương 2". Nội dung là văn bản thuần chứa `$...$` /
 * `$$...$$` đúng như T21/T22 (dùng `\lt`, `\gt` thay `<`, `>` sát chữ).
 */
type Q = { id: number; content: string; options: string[]; correct: number; explanation: string };

const BANK: Q[] = [
  {
    id: 9001,
    content: "Cho đường tròn $(O)$ và góc nội tiếp $\\widehat{BAC} = 35^\\circ$. Số đo cung $BC$ không chứa điểm $A$ là:",
    options: ["$35^\\circ$", "$70^\\circ$", "$17{,}5^\\circ$", "$145^\\circ$"],
    correct: 1,
    explanation: "Góc nội tiếp bằng nửa số đo cung bị chắn, nên sđ cung $BC = 2 \\cdot 35^\\circ = 70^\\circ$.",
  },
  {
    id: 9002,
    content: "Tứ giác $ABCD$ nội tiếp đường tròn, biết $\\widehat{A} = 3x + 10^\\circ$ và $\\widehat{C} = 2x$. Giá trị của $x$ là:",
    options: ["$x = 34^\\circ$", "$x = 38^\\circ$", "$x = 42^\\circ$", "$x = 56^\\circ$"],
    correct: 0,
    explanation: "Tứ giác nội tiếp có tổng hai góc đối bằng $180^\\circ$: $5x + 10^\\circ = 180^\\circ$ nên $x = 34^\\circ$.",
  },
  {
    id: 9003,
    content: "Độ dài cung tròn $90^\\circ$ của đường tròn bán kính $R = 6\\text{ cm}$ là:",
    options: ["$3\\pi\\text{ cm}$", "$6\\pi\\text{ cm}$", "$\\dfrac{3\\pi}{2}\\text{ cm}$", "$9\\pi\\text{ cm}$"],
    correct: 0,
    explanation: "$l = \\dfrac{\\pi R n}{180} = \\dfrac{\\pi \\cdot 6 \\cdot 90}{180} = 3\\pi$ (cm).",
  },
  {
    id: 9004,
    content: "Bán kính đường tròn ngoại tiếp tam giác đều cạnh $a$ là:",
    options: ["$\\dfrac{a\\sqrt{3}}{3}$", "$\\dfrac{a\\sqrt{3}}{2}$", "$\\dfrac{a\\sqrt{3}}{6}$", "$a\\sqrt{3}$"],
    correct: 0,
    explanation: "Tâm là trọng tâm, nên $R = \\dfrac{2}{3} \\cdot \\dfrac{a\\sqrt{3}}{2} = \\dfrac{a\\sqrt{3}}{3}$.",
  },
  {
    id: 9005,
    content: "Diện tích hình quạt tròn bán kính $R$, cung $n^\\circ$ được tính bởi công thức:",
    options: ["$S = \\dfrac{\\pi R^2 n}{360}$", "$S = \\dfrac{\\pi R n}{180}$", "$S = \\pi R^2$", "$S = \\dfrac{\\pi R^2 n}{180}$"],
    correct: 0,
    explanation: "Hình quạt $n^\\circ$ chiếm $\\dfrac{n}{360}$ hình tròn: $S = \\dfrac{\\pi R^2 n}{360}$.",
  },
  {
    id: 9006,
    content:
      "Cho biểu thức\n$$P = \\left(\\dfrac{\\sqrt{x}}{\\sqrt{x} - 1} - \\dfrac{1}{x - \\sqrt{x}}\\right) : \\dfrac{\\sqrt{x} + 1}{x}$$\nvới $x \\gt 0$, $x \\ne 1$. Rút gọn $P$ được:",
    options: ["$P = \\sqrt{x}$", "$P = \\dfrac{1}{\\sqrt{x}}$", "$P = x - 1$", "$P = \\dfrac{\\sqrt{x} + 1}{\\sqrt{x}}$"],
    correct: 0,
    explanation: "Quy đồng trong ngoặc được $\\dfrac{\\sqrt{x} + 1}{\\sqrt{x}}$, chia cho $\\dfrac{\\sqrt{x} + 1}{x}$ được $\\dfrac{x}{\\sqrt{x}} = \\sqrt{x}$.",
  },
  {
    id: 9007,
    content: "Góc tạo bởi tia tiếp tuyến và dây cung chắn cung $120^\\circ$ có số đo là:",
    options: ["$60^\\circ$", "$120^\\circ$", "$240^\\circ$", "$30^\\circ$"],
    correct: 0,
    explanation: "Góc tạo bởi tia tiếp tuyến và dây cung bằng nửa số đo cung bị chắn: $\\dfrac{120^\\circ}{2} = 60^\\circ$.",
  },
  {
    id: 9008,
    content: "Tam giác $ABC$ vuông tại $A$ có $AB = 6$, $AC = 8$. Bán kính đường tròn ngoại tiếp tam giác là:",
    options: ["$5$", "$10$", "$7$", "$4{,}8$"],
    correct: 0,
    explanation: "Tâm là trung điểm cạnh huyền: $BC = \\sqrt{6^2 + 8^2} = 10$ nên $R = 5$.",
  },
];

function options(q: Q): QuizOption[] {
  return q.options.map((content, i) => ({ id: q.id * 10 + i + 1, position: i + 1, content }));
}

export const quizMeta = { id: 502, title: "Luyện tập tổng hợp chương 2", time_limit_minutes: 15, course_id: 101, lesson_id: 307 };

/** POST /learn/quizzes/502/attempts → 200 (đang làm dở, đã trả lời 3 câu). */
export const attemptInProgress: AttemptInProgress = {
  id: 7001,
  quiz_id: 502,
  status: "in_progress",
  started_at: "2026-10-06T19:30:00+07:00",
  expires_at: "2026-10-06T19:45:00+07:00",
  server_now: "2026-10-06T19:33:20+07:00",
  remaining_seconds: 700,
  total_questions: BANK.length,
  answers: { "9001": 90012, "9002": 90021, "9003": 90033 },
  questions: BANK.map((q, i) => ({ id: q.id, position: i + 1, content: q.content, options: options(q) })),
};

/** Câu trả lời của lượt đã nộp: 6 đúng, 1 sai, 1 bỏ trống → 7,5 điểm. */
const picked: Record<number, number | null> = {
  9001: 90012, 9002: 90011, 9003: 90033, 9004: 90041, 9005: 90051, 9006: 90061, 9007: 90071, 9008: null,
};

/** POST /learn/quiz-attempts/7001/submit. */
export const attemptResult: AttemptResult = {
  id: 7001,
  quiz_id: 502,
  status: "submitted",
  started_at: "2026-10-06T19:30:00+07:00",
  submitted_at: "2026-10-06T19:44:10+07:00",
  auto_submitted: false,
  server_now: "2026-10-06T19:44:10+07:00",
  total_questions: BANK.length,
  correct_count: 6,
  unanswered_count: 1,
  score: 7.5,
  questions: BANK.map((q, i) => {
    const correctId = q.id * 10 + q.correct + 1;
    const sel = picked[q.id] ?? null;
    return {
      id: q.id,
      position: i + 1,
      content: q.content,
      explanation: q.explanation,
      selected_option_id: sel,
      correct_option_id: correctId,
      is_correct: sel === correctId,
      options: options(q),
    };
  }),
};
