/**
 * Tính tuổi tại thời điểm `now` (mặc định thời điểm gọi hàm) — dùng để quyết định hiện/ẩn
 * khối thông tin liên hệ phụ huynh khi đăng ký (US-001 BR8, US-017). Sinh nhật đúng ngày
 * `now` được tính là ĐÃ đủ tuổi đó (khớp US-001 "trường hợp biên": "học sinh sinh nhật
 * đúng ngày đăng ký chuyển từ dưới 18 sang đủ 18 tuổi").
 *
 * Đây CHỈ là gợi ý hiển thị phía client (học sinh có thể khai sai ngày sinh — rủi ro đã
 * biết, xem US-001 "Trường hợp biên"). Server luôn tính lại theo ngày giờ server, đây
 * không phải lớp kiểm tra duy nhất.
 */
export function calculateAgeYears(dateOfBirth: string, now: Date = new Date()): number | null {
  const dob = new Date(dateOfBirth);
  if (Number.isNaN(dob.getTime())) return null;

  let age = now.getFullYear() - dob.getFullYear();
  const hasHadBirthdayThisYear =
    now.getMonth() > dob.getMonth() ||
    (now.getMonth() === dob.getMonth() && now.getDate() >= dob.getDate());
  if (!hasHadBirthdayThisYear) {
    age -= 1;
  }
  return age;
}
