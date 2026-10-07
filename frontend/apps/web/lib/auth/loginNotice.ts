/** Thông báo đến trang đăng nhập từ trang khác qua `?trang-thai=` (chỉ nhận đúng các khoá này). Không phải module client: trang server gọi được. */
export type LoginNotice = "het-phien" | "dat-lai-xong";

export function parseLoginNotice(raw: string | undefined): LoginNotice | undefined {
  return raw === "het-phien" || raw === "dat-lai-xong" ? raw : undefined;
}
