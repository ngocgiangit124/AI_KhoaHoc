/**
 * Dữ liệu mẫu cho bản xem trước quản trị v2. Tên trường theo api-contract §2.5:
 * StaffUser (T28), CourseListResource / CourseResource (T08), ChapterResource / LessonResource (T09, T11).
 */
export type StaffRole = "admin" | "quan_ly_trang" | "giao_vien";

export interface StaffUser {
  id: number;
  name: string;
  email: string;
  role: StaffRole;
  must_change_password: boolean;
  permissions: {
    manage_system: boolean;
    manage_subjects: boolean;
    manage_all_courses: boolean;
    manage_coupons: boolean;
    view_orders: boolean;
    export_orders: boolean;
    export_orders_with_contact: boolean;
  };
  session: { idle_timeout_minutes: number; expires_at: string };
}

export const ROLE_LABEL: Record<StaffRole, string> = { admin: "Admin", quan_ly_trang: "Quản lý trang", giao_vien: "Giáo viên" };

const session = { idle_timeout_minutes: 120, expires_at: "2026-10-07T07:00:00+07:00" };

export const STAFF: Record<StaffRole, StaffUser> = {
  admin: {
    id: 1, name: "Lê Văn Hùng", email: "hung.le@vitaminvui.vn", role: "admin", must_change_password: false, session,
    permissions: { manage_system: true, manage_subjects: true, manage_all_courses: true, manage_coupons: true, view_orders: true, export_orders: true, export_orders_with_contact: true },
  },
  quan_ly_trang: {
    id: 2, name: "Đỗ Thị Mai", email: "mai.do@vitaminvui.vn", role: "quan_ly_trang", must_change_password: false, session,
    permissions: { manage_system: false, manage_subjects: true, manage_all_courses: true, manage_coupons: true, view_orders: true, export_orders: true, export_orders_with_contact: false },
  },
  giao_vien: {
    id: 11, name: "Nguyễn Thu Hà", email: "ha.nguyen@vitaminvui.vn", role: "giao_vien", must_change_password: false, session,
    permissions: { manage_system: false, manage_subjects: false, manage_all_courses: false, manage_coupons: false, view_orders: false, export_orders: false, export_orders_with_contact: false },
  },
};

export type CourseStatus = "draft" | "published" | "unpublished";
export const STATUS_LABEL: Record<CourseStatus, string> = { draft: "Nháp", published: "Đã xuất bản", unpublished: "Ngừng bán" };

export interface AdminCourse {
  id: number;
  title: string;
  slug: string;
  short_description: string | null;
  grade_level: number;
  price: number;
  thumbnail_url: string | null;
  status: CourseStatus;
  published_at: string | null;
  manual_order?: number | null;
  enrollments_count: number;
  subjects: Array<{ id: number; name: string; slug: string }>;
  teachers: Array<{ id: number; name: string }>;
  created_by: number | null;
  created_at: string;
  updated_at: string;
}

const S = {
  soHoc: { id: 1, name: "Số học", slug: "so-hoc" },
  daiSo: { id: 2, name: "Đại số", slug: "dai-so" },
  hinh: { id: 3, name: "Hình học", slug: "hinh-hoc" },
  giaiTich: { id: 4, name: "Hàm số và giải tích", slug: "giai-tich" },
  xs: { id: 5, name: "Xác suất – thống kê", slug: "xac-suat-thong-ke" },
  vao10: { id: 6, name: "Ôn thi vào 10", slug: "on-thi-vao-10" },
  thpt: { id: 7, name: "Ôn thi THPT", slug: "on-thi-thpt" },
};
export const SUBJECTS = Object.values(S);

export const TEACHERS = [
  { id: 11, name: "Nguyễn Thu Hà" },
  { id: 12, name: "Trần Minh Đức" },
  { id: 13, name: "Phạm Ngọc Lan" },
  { id: 14, name: "Lê Đăng Khoa" },
];
const [HA, DUC, LAN, KHOA] = TEACHERS as [typeof TEACHERS[0], typeof TEACHERS[0], typeof TEACHERS[0], typeof TEACHERS[0]];

function c(p: Partial<AdminCourse> & Pick<AdminCourse, "id" | "title" | "slug" | "grade_level" | "price" | "status" | "subjects" | "teachers">): AdminCourse {
  return {
    short_description: null, thumbnail_url: null, published_at: p.status === "draft" ? null : "2026-08-20T08:00:00+07:00",
    manual_order: null, enrollments_count: 0, created_by: 1, created_at: "2026-08-01T09:00:00+07:00", updated_at: "2026-10-05T16:40:00+07:00",
    ...p,
  };
}

/** GET /admin/courses (sắp created_at desc). */
export const ADMIN_COURSES: AdminCourse[] = [
  c({ id: 114, title: "Hình học 10: Vectơ và hệ trục tọa độ", slug: "hinh-hoc-10-vecto", grade_level: 10, price: 449000, status: "draft", subjects: [S.hinh], teachers: [HA], created_at: "2026-10-05T14:00:00+07:00" }),
  c({ id: 108, title: "Hình học 8: Tứ giác và định lý Ta-lét", slug: "hinh-hoc-8-ta-let", grade_level: 8, price: 329000, status: "published", subjects: [S.hinh], teachers: [KHOA], enrollments_count: 0 }),
  c({ id: 112, title: "Xác suất – thống kê ôn thi tốt nghiệp THPT", slug: "xac-suat-thong-ke-thpt", grade_level: 12, price: 399000, status: "published", subjects: [S.xs, S.thpt], teachers: [KHOA], enrollments_count: 720 }),
  c({ id: 103, title: "Ôn thi vào lớp 10: 30 đề chọn lọc có chữa chi tiết", slug: "on-thi-vao-10-30-de", grade_level: 9, price: 599000, status: "published", subjects: [S.vao10], teachers: [DUC, HA], enrollments_count: 2105, manual_order: 1 }),
  c({ id: 107, title: "Đại số 7: Biểu thức đại số và đa thức", slug: "dai-so-7-da-thuc", grade_level: 7, price: 299000, status: "published", subjects: [S.daiSo], teachers: [KHOA], enrollments_count: 310 }),
  c({ id: 101, title: "Hình học 9: Đường tròn từ cơ bản đến nâng cao", slug: "hinh-hoc-9-duong-tron", grade_level: 9, price: 399000, status: "published", subjects: [S.hinh], teachers: [HA], enrollments_count: 1240, manual_order: 2 }),
  c({ id: 109, title: "Hàm số bậc nhất và bậc hai lớp 10", slug: "ham-so-bac-nhat-bac-hai-10", grade_level: 10, price: 449000, status: "published", subjects: [S.giaiTich], teachers: [DUC], enrollments_count: 980 }),
  c({ id: 102, title: "Phương trình bậc hai và hệ thức Vi-ét", slug: "phuong-trinh-bac-hai-vi-et", grade_level: 9, price: 349000, status: "unpublished", subjects: [S.daiSo], teachers: [DUC], enrollments_count: 860 }),
  c({ id: 110, title: "Lượng giác 11: công thức và phương trình", slug: "luong-giac-11", grade_level: 11, price: 449000, status: "published", subjects: [S.daiSo], teachers: [HA], enrollments_count: 640 }),
  c({ id: 104, title: "Căn bậc hai, căn bậc ba: học chắc nền tảng", slug: "can-bac-hai-can-bac-ba", grade_level: 9, price: 0, status: "published", subjects: [S.daiSo], teachers: [LAN], enrollments_count: 3410 }),
  c({ id: 105, title: "Số học 6: Số tự nhiên và phép chia hết", slug: "so-hoc-6-chia-het", grade_level: 6, price: 0, status: "published", subjects: [S.soHoc], teachers: [LAN], enrollments_count: 1520 }),
  c({ id: 111, title: "Đạo hàm và ứng dụng — Toán 12", slug: "dao-ham-ung-dung-12", grade_level: 12, price: 499000, status: "published", subjects: [S.giaiTich, S.thpt], teachers: [DUC], enrollments_count: 1890 }),
];

/** Số yêu cầu đăng ký miễn phí đang chờ (GET /admin/enrollment-requests meta.total). */
export const PENDING_REQUESTS = 4;

export type VideoSource = "none" | "upload" | "external_link";
export type VideoStatus = "created" | "uploading" | "processing" | "ready" | "failed" | null;

/** LessonResource (T09) + `upload_percent` chỉ có ở FE khi đang tải TUS. */
export interface AdminLesson {
  id: number;
  course_id: number;
  chapter_id: number;
  title: string;
  position: number;
  is_preview: boolean;
  video_source: VideoSource;
  duration_seconds: number | null;
  external_provider: "youtube" | "vimeo" | null;
  external_video_id: string | null;
  external_embed_url: string | null;
  has_video_asset: boolean;
  video_status: VideoStatus;
  upload_percent?: number;
  /** GET .../video `error_message` khi failed (VIDEO_INVALID / transcode lỗi). */
  error_message?: string | null;
}

export interface AdminChapter {
  id: number;
  course_id: number;
  title: string;
  position: number;
  lessons: AdminLesson[];
}

function L(id: number, chapter: number, position: number, title: string, extra: Partial<AdminLesson> = {}): AdminLesson {
  return {
    id, course_id: 101, chapter_id: chapter, title, position, is_preview: false, video_source: "upload", duration_seconds: 700,
    external_provider: null, external_video_id: null, external_embed_url: null, has_video_asset: true, video_status: "ready", ...extra,
  };
}

/** GET /admin/courses/101/chapters. */
export const CHAPTERS: AdminChapter[] = [
  {
    id: 201, course_id: 101, title: "Chương 1. Đường tròn và vị trí tương đối", position: 1,
    lessons: [
      L(301, 201, 1, "Bài 1. Sự xác định đường tròn", { is_preview: true, video_source: "external_link", has_video_asset: false, video_status: null, external_provider: "youtube", external_video_id: "dQw4w9WgXcQ", external_embed_url: "https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ", duration_seconds: 612 }),
      L(302, 201, 2, "Bài 2. Đường kính và dây cung", { duration_seconds: 745 }),
      L(303, 201, 3, "Bài 3. Liên hệ giữa dây và khoảng cách từ tâm đến dây", { duration_seconds: 830 }),
      L(304, 201, 4, "Bài 4. Vị trí tương đối của đường thẳng và đường tròn", { duration_seconds: 905 }),
      L(305, 201, 5, "Bài 5. Tiếp tuyến của đường tròn", { is_preview: true, duration_seconds: 1010 }),
    ],
  },
  {
    id: 202, course_id: 101, title: "Chương 2. Góc với đường tròn", position: 2,
    lessons: [
      L(306, 202, 1, "Bài 6. Góc ở tâm, số đo cung", { duration_seconds: 665 }),
      L(307, 202, 2, "Bài 7. Góc nội tiếp", { duration_seconds: 760 }),
      L(308, 202, 3, "Bài 8. Góc tạo bởi tia tiếp tuyến và dây cung", { video_status: "processing", duration_seconds: null }),
      L(309, 202, 4, "Bài 9. Góc có đỉnh bên trong, bên ngoài đường tròn", { video_status: "uploading", upload_percent: 45, duration_seconds: null }),
      L(310, 202, 5, "Bài 10. Tứ giác nội tiếp", {
        video_status: "failed", duration_seconds: null,
        error_message: "Tệp tải lên không phải video hợp lệ. Hãy chọn tệp MP4, MOV, MKV hoặc WebM khác.",
      }),
      L(311, 202, 6, "Bài 11. Luyện tập: chứng minh tứ giác nội tiếp", { video_source: "none", has_video_asset: false, video_status: null, duration_seconds: null }),
    ],
  },
  {
    id: 203, course_id: 101, title: "Chương 3. Độ dài đường tròn, diện tích hình tròn", position: 3,
    lessons: [
      L(312, 203, 1, "Bài 12. Độ dài đường tròn, cung tròn", { duration_seconds: 720 }),
      L(313, 203, 2, "Bài 13. Diện tích hình tròn, hình quạt tròn", { duration_seconds: 780 }),
      L(314, 203, 3, "Bài 14. Luyện tập tổng hợp", { duration_seconds: 1150 }),
    ],
  },
  { id: 204, course_id: 101, title: "Chương 4. Ôn tập và đề kiểm tra", position: 4, lessons: [] },
];

export const COURSE_DESCRIPTION =
  "Khóa học đi hết chương Đường tròn của Toán 9 theo đúng thứ tự sách giáo khoa. Mỗi bài là một video 10–25 phút: nhắc lại định nghĩa, chứng minh định lý bằng hình vẽ từng bước, rồi giải 3–4 ví dụ từ dễ đến khó.";
