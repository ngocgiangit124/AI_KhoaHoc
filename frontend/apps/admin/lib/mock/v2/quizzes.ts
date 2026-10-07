/**
 * Dữ liệu mẫu soạn quiz (FA5). Tên trường theo api-contract §2.5 T21:
 * QuizResource{id,course_id,chapter_id,lesson_id,parent_type,parent_title,title,time_limit_minutes,position,questions_count,created_at,updated_at}
 * QuizQuestionResource{id,quiz_id,content,explanation,position,options:[{id,content,is_correct,position}],created_at,updated_at}
 * Nội dung là VĂN BẢN THUẦN có `$...$` / `$$...$$`; `<`, `>` sát chữ phải viết `\lt`, `\gt`.
 */
export interface QuizResource {
  id: number;
  course_id: number;
  chapter_id: number | null;
  lesson_id: number | null;
  parent_type: "chapter" | "lesson";
  parent_title: string;
  title: string;
  time_limit_minutes: number | null;
  position: number;
  questions_count: number;
  created_at: string;
  updated_at: string;
}

export interface QuizOptionResource {
  id: number;
  content: string;
  is_correct: boolean;
  position: number;
}

export interface QuizQuestionResource {
  id: number;
  quiz_id: number;
  content: string;
  explanation: string | null;
  position: number;
  options: QuizOptionResource[];
  created_at: string;
  updated_at: string;
}

/** Giới hạn từ contract (T21). */
export const QUIZ_LIMITS = { content: 5000, explanation: 5000, option: 1000, questions: 200, title: 255, timeMin: 1, timeMax: 300 } as const;

const T = "2026-10-05T16:40:00+07:00";

/** GET /admin/courses/101/quizzes. */
export const QUIZZES: QuizResource[] = [
  { id: 501, course_id: 101, chapter_id: null, lesson_id: 307, parent_type: "lesson", parent_title: "Bài 7. Góc nội tiếp", title: "Kiểm tra nhanh: Góc nội tiếp", time_limit_minutes: 10, position: 1, questions_count: 5, created_at: T, updated_at: T },
  { id: 502, course_id: 101, chapter_id: 202, lesson_id: null, parent_type: "chapter", parent_title: "Chương 2. Góc với đường tròn", title: "Luyện tập tổng hợp chương 2", time_limit_minutes: 15, position: 2, questions_count: 8, created_at: T, updated_at: "2026-10-06T20:15:00+07:00" },
  { id: 503, course_id: 101, chapter_id: 201, lesson_id: null, parent_type: "chapter", parent_title: "Chương 1. Đường tròn và vị trí tương đối", title: "Đề kiểm tra chương 1", time_limit_minutes: 45, position: 3, questions_count: 0, created_at: T, updated_at: T },
  { id: 504, course_id: 101, chapter_id: null, lesson_id: 312, parent_type: "lesson", parent_title: "Bài 12. Độ dài đường tròn, cung tròn", title: "Bài tập: Độ dài cung tròn", time_limit_minutes: null, position: 4, questions_count: 6, created_at: T, updated_at: T },
];

type Raw = { id: number; content: string; options: string[]; correct: number; explanation: string | null };

const RAW: Raw[] = [
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
    explanation: null,
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

/** GET /admin/courses/101/quizzes/502 → `questions`. */
export const QUESTIONS: QuizQuestionResource[] = RAW.map((r, i) => ({
  id: r.id,
  quiz_id: 502,
  content: r.content,
  explanation: r.explanation,
  position: i + 1,
  options: r.options.map((content, j) => ({ id: r.id * 10 + j + 1, content, is_correct: j === r.correct, position: j + 1 })),
  created_at: T,
  updated_at: T,
}));

/** Câu mẫu bị server từ chối (422) để xem trạng thái lỗi: `<b>` dạng thẻ HTML + 2 đáp án đúng. */
export const INVALID_DRAFT: Pick<QuizQuestionResource, "content" | "explanation"> & { options: Array<{ content: string; is_correct: boolean }> } = {
  content: "So sánh: nếu $a<b$ và $b<c$ thì <b>luôn</b> có:",
  explanation: "",
  options: [
    { content: "$a \\lt c$", is_correct: true },
    { content: "$a \\gt c$", is_correct: true },
    { content: "$a = c$", is_correct: false },
    { content: "", is_correct: false },
  ],
};

export function getQuiz(id: number): QuizResource | undefined {
  return QUIZZES.find((q) => q.id === id);
}
