import type { CatalogCourse, PublicConfig, Subject } from "./types";

/** GET /config/public — bản xem trước giữ đúng trạng thái hiện tại: thanh toán tạm khoá. */
export const publicConfig: PublicConfig = {
  quiz_time_limit_enabled: true,
  otp: { ttl_minutes: 10, resend_cooldown_seconds: 60 },
  grades: [6, 7, 8, 9, 10, 11, 12],
  captcha_site_key: null,
  policy_version: "2026-09",
  parent_consent_age: 18,
  paid_checkout_enabled: false,
};

export const subjects: Subject[] = [
  { id: 1, name: "Số học", slug: "so-hoc" },
  { id: 2, name: "Đại số", slug: "dai-so" },
  { id: 3, name: "Hình học", slug: "hinh-hoc" },
  { id: 4, name: "Hàm số và giải tích", slug: "giai-tich" },
  { id: 5, name: "Xác suất – thống kê", slug: "xac-suat-thong-ke" },
  { id: 6, name: "Ôn thi vào 10", slug: "on-thi-vao-10" },
  { id: 7, name: "Ôn thi THPT", slug: "on-thi-thpt" },
];

const s = (id: number): Subject => subjects.find((x) => x.id === id) as Subject;
const HA = { id: 11, name: "Nguyễn Thu Hà" };
const DUC = { id: 12, name: "Trần Minh Đức" };
const LAN = { id: 13, name: "Phạm Ngọc Lan" };
const KHOA = { id: 14, name: "Lê Đăng Khoa" };

/** Item GET /courses. `thumbnail_url` null: bản xem trước không có ảnh thật nên dùng bìa dựng sẵn. */
export const catalogCourses: CatalogCourse[] = [
  {
    id: 101, title: "Hình học 9: Đường tròn từ cơ bản đến nâng cao", slug: "hinh-hoc-9-duong-tron",
    short_description: "Góc với đường tròn, tứ giác nội tiếp, độ dài cung và diện tích hình quạt — đủ cho bài thi vào 10.",
    grade_level: 9, price: 399000, is_free: false, thumbnail_url: null, enrollments_count: 1240,
    published_at: "2026-08-20T08:00:00+07:00", subjects: [s(3)], teachers: [HA],
  },
  {
    id: 102, title: "Phương trình bậc hai và hệ thức Vi-ét", slug: "phuong-trinh-bac-hai-vi-et",
    short_description: "Giải phương trình, biện luận nghiệm và các dạng bài Vi-ét hay gặp trong đề thi.",
    grade_level: 9, price: 349000, is_free: false, thumbnail_url: null, enrollments_count: 860,
    published_at: "2026-08-12T08:00:00+07:00", subjects: [s(2)], teachers: [DUC],
  },
  {
    id: 103, title: "Ôn thi vào lớp 10: 30 đề chọn lọc có chữa chi tiết", slug: "on-thi-vao-10-30-de",
    short_description: "Mỗi đề có video chữa từng câu, chỉ ra lỗi sai hay gặp và cách trình bày đủ điểm.",
    grade_level: 9, price: 599000, is_free: false, thumbnail_url: null, enrollments_count: 2105,
    published_at: "2026-09-01T08:00:00+07:00", subjects: [s(6)], teachers: [DUC, HA],
  },
  {
    id: 104, title: "Căn bậc hai, căn bậc ba: học chắc nền tảng", slug: "can-bac-hai-can-bac-ba",
    short_description: "Khóa miễn phí: biến đổi căn thức, rút gọn biểu thức và bài toán chứa căn.",
    grade_level: 9, price: 0, is_free: true, thumbnail_url: null, enrollments_count: 3410,
    published_at: "2026-07-15T08:00:00+07:00", subjects: [s(2)], teachers: [LAN],
  },
  {
    id: 105, title: "Số học 6: Số tự nhiên và phép chia hết", slug: "so-hoc-6-chia-het",
    short_description: "Ước, bội, số nguyên tố, ƯCLN và BCNN qua ví dụ gần gũi.",
    grade_level: 6, price: 0, is_free: true, thumbnail_url: null, enrollments_count: 1520,
    published_at: "2026-07-01T08:00:00+07:00", subjects: [s(1)], teachers: [LAN],
  },
  {
    id: 106, title: "Phân số và số thập phân lớp 6", slug: "phan-so-so-thap-phan-6",
    short_description: "Rút gọn, quy đồng, bốn phép tính với phân số và bài toán thực tế.",
    grade_level: 6, price: 249000, is_free: false, thumbnail_url: null, enrollments_count: 430,
    published_at: "2026-08-05T08:00:00+07:00", subjects: [s(1)], teachers: [LAN],
  },
  {
    id: 107, title: "Đại số 7: Biểu thức đại số và đa thức", slug: "dai-so-7-da-thuc",
    short_description: "Thu gọn, cộng trừ đa thức một biến và tìm nghiệm đa thức.",
    grade_level: 7, price: 299000, is_free: false, thumbnail_url: null, enrollments_count: 310,
    published_at: "2026-08-25T08:00:00+07:00", subjects: [s(2)], teachers: [KHOA],
  },
  {
    id: 108, title: "Hình học 8: Tứ giác và định lý Ta-lét", slug: "hinh-hoc-8-ta-let",
    short_description: "Hình thang, hình bình hành, định lý Ta-lét và tam giác đồng dạng.",
    grade_level: 8, price: 329000, is_free: false, thumbnail_url: null, enrollments_count: 0,
    published_at: "2026-10-02T08:00:00+07:00", subjects: [s(3)], teachers: [KHOA],
  },
  {
    id: 109, title: "Hàm số bậc nhất và bậc hai lớp 10", slug: "ham-so-bac-nhat-bac-hai-10",
    short_description: "Đồ thị, chiều biến thiên và bài toán tương giao giữa đường thẳng và parabol.",
    grade_level: 10, price: 449000, is_free: false, thumbnail_url: null, enrollments_count: 980,
    published_at: "2026-08-18T08:00:00+07:00", subjects: [s(4)], teachers: [DUC],
  },
  {
    id: 110, title: "Lượng giác 11: công thức và phương trình", slug: "luong-giac-11",
    short_description: "Hệ thống công thức lượng giác và các dạng phương trình lượng giác cơ bản.",
    grade_level: 11, price: 449000, is_free: false, thumbnail_url: null, enrollments_count: 640,
    published_at: "2026-08-08T08:00:00+07:00", subjects: [s(2)], teachers: [HA],
  },
  {
    id: 111, title: "Đạo hàm và ứng dụng — Toán 12", slug: "dao-ham-ung-dung-12",
    short_description: "Tính đơn điệu, cực trị, giá trị lớn nhất – nhỏ nhất và khảo sát hàm số.",
    grade_level: 12, price: 499000, is_free: false, thumbnail_url: null, enrollments_count: 1890,
    published_at: "2026-07-28T08:00:00+07:00", subjects: [s(4), s(7)], teachers: [DUC],
  },
  {
    id: 112, title: "Xác suất – thống kê ôn thi tốt nghiệp THPT", slug: "xac-suat-thong-ke-thpt",
    short_description: "Biến cố, xác suất có điều kiện và các câu thống kê trong đề minh hoạ.",
    grade_level: 12, price: 399000, is_free: false, thumbnail_url: null, enrollments_count: 720,
    published_at: "2026-09-10T08:00:00+07:00", subjects: [s(5), s(7)], teachers: [KHOA],
  },
  {
    id: 113, title: "Bồi dưỡng học sinh giỏi Toán 9", slug: "boi-duong-hsg-toan-9",
    short_description: "Khóa miễn phí cho học sinh lớp chọn: bất đẳng thức, số học và hình học nâng cao.",
    grade_level: 9, price: 0, is_free: true, thumbnail_url: null, enrollments_count: 85,
    published_at: "2026-09-20T08:00:00+07:00", subjects: [s(2), s(3)], teachers: [HA],
  },
];

export function findCourse(slug: string): CatalogCourse | undefined {
  return catalogCourses.find((c) => c.slug === slug);
}

export function findCourseById(id: number): CatalogCourse | undefined {
  return catalogCourses.find((c) => c.id === id);
}
