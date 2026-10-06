import { catalogCourses, findCourse } from "./catalog";
import type { CourseDetail } from "./types";

type Outline = CourseDetail["outline"];

/** Chương/bài của khóa "Hình học 9: Đường tròn" (id 101), dùng chung cho trang chi tiết và trang học. */
export const circleOutline: Outline = [
  {
    id: 201, title: "Chương 1. Đường tròn và vị trí tương đối", position: 1,
    lessons: [
      { id: 301, title: "Bài 1. Sự xác định đường tròn", position: 1, duration_seconds: 612, is_preview: true },
      { id: 302, title: "Bài 2. Đường kính và dây cung", position: 2, duration_seconds: 745, is_preview: false },
      { id: 303, title: "Bài 3. Liên hệ giữa dây và khoảng cách từ tâm đến dây", position: 3, duration_seconds: 830, is_preview: false },
      { id: 304, title: "Bài 4. Vị trí tương đối của đường thẳng và đường tròn", position: 4, duration_seconds: 905, is_preview: false },
      { id: 305, title: "Bài 5. Tiếp tuyến của đường tròn", position: 5, duration_seconds: 1010, is_preview: true },
    ],
  },
  {
    id: 202, title: "Chương 2. Góc với đường tròn", position: 2,
    lessons: [
      { id: 306, title: "Bài 6. Góc ở tâm, số đo cung", position: 1, duration_seconds: 665, is_preview: false },
      { id: 307, title: "Bài 7. Góc nội tiếp", position: 2, duration_seconds: 760, is_preview: false },
      { id: 308, title: "Bài 8. Góc tạo bởi tia tiếp tuyến và dây cung", position: 3, duration_seconds: 860, is_preview: false },
      { id: 309, title: "Bài 9. Góc có đỉnh bên trong, bên ngoài đường tròn", position: 4, duration_seconds: 795, is_preview: false },
      { id: 310, title: "Bài 10. Tứ giác nội tiếp", position: 5, duration_seconds: 990, is_preview: false },
      { id: 311, title: "Bài 11. Luyện tập: chứng minh tứ giác nội tiếp", position: 6, duration_seconds: 1090, is_preview: false },
    ],
  },
  {
    id: 203, title: "Chương 3. Độ dài đường tròn, diện tích hình tròn", position: 3,
    lessons: [
      { id: 312, title: "Bài 12. Độ dài đường tròn, cung tròn", position: 1, duration_seconds: 720, is_preview: false },
      { id: 313, title: "Bài 13. Diện tích hình tròn, hình quạt tròn", position: 2, duration_seconds: 780, is_preview: false },
      { id: 314, title: "Bài 14. Luyện tập tổng hợp", position: 3, duration_seconds: 1150, is_preview: false },
    ],
  },
  {
    id: 204, title: "Chương 4. Ôn tập và đề kiểm tra", position: 4,
    lessons: [
      { id: 315, title: "Bài 15. Hệ thống kiến thức chương đường tròn", position: 1, duration_seconds: 1320, is_preview: false },
      { id: 316, title: "Bài 16. Chữa đề kiểm tra 45 phút", position: 2, duration_seconds: 1500, is_preview: false },
    ],
  },
];

/**
 * `description` thật là HTML đã lọc, render qua `CourseDescription` (DOMPurify) của FW2.
 * Bản xem trước giữ dạng đoạn văn thuần để không phải dùng dangerouslySetInnerHTML.
 */
const circleDescription = [
  "Khóa học đi hết chương Đường tròn của Toán 9 theo đúng thứ tự sách giáo khoa. Mỗi bài là một video 10–25 phút: nhắc lại định nghĩa, chứng minh định lý bằng hình vẽ từng bước, rồi giải 3–4 ví dụ từ dễ đến khó.",
  "Sau mỗi bài có trắc nghiệm ngắn để kiểm tra ngay. Câu sai có lời giải, chỉ ra bước dễ nhầm. Cuối khóa có 2 đề kiểm tra 45 phút chữa chi tiết, sát cấu trúc đề thi vào 10.",
  "Phù hợp với học sinh lớp 9 muốn học chắc phần hình, và học sinh ôn thi vào 10 cần hệ thống lại cách chứng minh tứ giác nội tiếp.",
].join("\n\n");

export function genericOutline(courseId: number): Outline {
  return [
    {
      id: courseId * 10 + 1, title: "Chương 1. Kiến thức nền", position: 1,
      lessons: [
        { id: courseId * 100 + 1, title: "Bài 1. Nhắc lại kiến thức cần nhớ", position: 1, duration_seconds: 640, is_preview: true },
        { id: courseId * 100 + 2, title: "Bài 2. Ví dụ mở đầu", position: 2, duration_seconds: 720, is_preview: false },
        { id: courseId * 100 + 3, title: "Bài 3. Luyện tập", position: 3, duration_seconds: 900, is_preview: false },
      ],
    },
    {
      id: courseId * 10 + 2, title: "Chương 2. Các dạng bài thường gặp", position: 2,
      lessons: [
        { id: courseId * 100 + 4, title: "Bài 4. Dạng 1 và cách nhận biết", position: 1, duration_seconds: 840, is_preview: false },
        { id: courseId * 100 + 5, title: "Bài 5. Dạng 2 và lỗi sai hay gặp", position: 2, duration_seconds: 910, is_preview: false },
      ],
    },
  ];
}

/** GET /courses/{slug}. Khóa "khoa-moi-chua-co-bai" minh hoạ trạng thái outline rỗng. */
export function getCourseDetail(slug: string): CourseDetail | null {
  const base = findCourse(slug) ?? (slug === "khoa-moi-chua-co-bai" ? catalogCourses[7] : undefined);
  if (!base) return null;
  const isCircle = base.id === 101;
  const outline = slug === "khoa-moi-chua-co-bai" ? [] : isCircle ? circleOutline : genericOutline(base.id);
  const lessons = outline.flatMap((c) => c.lessons);
  return {
    ...base,
    slug,
    description: isCircle ? circleDescription : `${base.short_description ?? ""}\n\nKhóa học gồm video bài giảng và trắc nghiệm sau mỗi bài.`,
    teachers: base.teachers.map((t) => ({
      ...t,
      bio:
        t.id === 11
          ? "12 năm dạy Toán THCS tại Hà Nội, chuyên luyện thi vào 10 phần hình học."
          : t.id === 12
            ? "Giáo viên Toán THPT, tác giả nhiều chuyên đề ôn thi tốt nghiệp."
            : "Giáo viên Toán, phụ trách các khóa nền tảng.",
      avatar_url: null,
    })),
    lessons_count: lessons.length,
    total_duration_seconds: lessons.reduce((sum, l) => sum + l.duration_seconds, 0),
    has_preview: lessons.some((l) => l.is_preview),
    outline,
  };
}
