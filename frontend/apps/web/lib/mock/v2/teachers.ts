import type { HomeTeacher } from "@vitaminvui/ui/v2";

/**
 * API trang chủ của US-020 (dự kiến `GET /home/teachers` → `{data: HomeTeacher[]}`, tối đa 6, đã lọc theo
 * BR2: đồng ý công khai + có ảnh + có bio + có khóa đang bán + Admin bật). Tên trường TẠM, chờ Architect chốt
 * trong api-contract.md. `avatar_url` null ở bản xem trước vì không có ảnh thật → thẻ hiện chữ cái đầu (AC17).
 */
export const homeTeachers: HomeTeacher[] = [
  {
    id: 11, name: "Nguyễn Thu Hà", avatar_url: null, headline: "Giáo viên Toán THCS, chuyên hình học thi vào 10",
    grade_levels: [9, 11], published_courses_count: 3,
    bio: "12 năm dạy Toán THCS tại Hà Nội. Cô thích vẽ hình thật chậm, từng bước, để học sinh tự nhìn ra lời giải.\nPhụ trách các khóa hình học lớp 9 và lượng giác lớp 11.",
  },
  {
    id: 12, name: "Trần Minh Đức", avatar_url: null, headline: "Giáo viên Toán THPT",
    grade_levels: [9, 10, 12], published_courses_count: 4,
    bio: "Dạy Toán THPT, soạn nhiều chuyên đề ôn thi tốt nghiệp. Bài giảng của thầy tập trung vào cách trình bày đủ ý để không mất điểm oan.",
  },
  {
    id: 13, name: "Phạm Ngọc Lan", avatar_url: null, headline: "Giáo viên Toán THCS",
    grade_levels: [6, 9], published_courses_count: 3,
    bio: "Phụ trách các khóa nền tảng: số học lớp 6, căn thức lớp 9. Cô chia bài thành phần ngắn, có ví dụ gần gũi với đời sống.",
  },
  {
    id: 14, name: "Lê Đăng Khoa", avatar_url: null, headline: null,
    grade_levels: [7, 8, 12], published_courses_count: 3,
    bio: "Dạy đại số lớp 7, hình học lớp 8 và xác suất – thống kê lớp 12.",
  },
  {
    id: 15, name: "Vũ Hải Yến", avatar_url: null, headline: "Giáo viên Toán THPT chuyên",
    grade_levels: [10, 11], published_courses_count: 2,
    bio: "Dạy hàm số và lượng giác cho học sinh lớp 10, 11. Mỗi bài có phần “lỗi hay gặp” để học sinh tự kiểm tra lại cách làm của mình trước khi sang bài mới.",
  },
  {
    id: 16, name: "Hoàng Quốc Bảo", avatar_url: null, headline: "Giáo viên Toán THCS",
    grade_levels: [6, 7], published_courses_count: 2,
    bio: "Đồng hành cùng học sinh mới lên cấp 2: phân số, số thập phân, biểu thức đại số.",
  },
];
