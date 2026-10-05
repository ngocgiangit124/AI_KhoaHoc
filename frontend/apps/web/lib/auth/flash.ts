/** Cờ MỘT LẦN cho trang chủ sau khi xác thực OTP thành công (sessionStorage, không chứa dữ liệu cá nhân). */
export type AccountFlash = "verified";

const KEY = "vv:account-flash";

export function setAccountFlash(kind: AccountFlash): void {
  try {
    sessionStorage.setItem(KEY, kind);
  } catch {
    // sessionStorage bị chặn (chế độ riêng tư): bỏ qua, chỉ mất thông báo.
  }
}

/** Đọc rồi xoá ngay — F5 hoặc mở lại trang chủ không hiện lại. */
export function consumeAccountFlash(): AccountFlash | null {
  try {
    const value = sessionStorage.getItem(KEY);
    sessionStorage.removeItem(KEY);
    return value === "verified" ? value : null;
  } catch {
    return null;
  }
}
