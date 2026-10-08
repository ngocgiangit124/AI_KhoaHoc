const VN_DATE = new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Ho_Chi_Minh", year: "numeric", month: "2-digit", day: "2-digit" });

/** `vitaminvui-du-lieu-ca-nhan-YYYYMMDD.json` theo ngày giờ VN (khớp tên backend đặt). */
export function defaultExportFilename(now: Date = new Date()): string {
  return `vitaminvui-du-lieu-ca-nhan-${VN_DATE.format(now).replace(/-/g, "")}.json`;
}

/**
 * Tên file từ `Content-Disposition`. Chỉ nhận ký tự an toàn (chữ/số/`._-`) và đuôi `.json`; mọi thứ khác (thiếu header, CORS không expose,
 * đường dẫn `../`, ký tự lạ) -> tên mặc định. Tên do server đặt nhưng FE không tin tuyệt đối khi gắn vào `download`.
 */
export function filenameFromDisposition(header: string | null, now: Date = new Date()): string {
  if (header) {
    const m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(header);
    const name = m?.[1]?.trim();
    if (name && /^[A-Za-z0-9._-]{1,120}\.json$/.test(name) && !name.includes("..")) return name;
  }
  return defaultExportFilename(now);
}

/** Tải blob xuống máy qua thẻ `<a download>` tạm. */
export function saveBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  a.rel = "noopener";
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10_000);
}
