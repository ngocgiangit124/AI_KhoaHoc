export const THUMBNAIL_MAX_BYTES = 2 * 1024 * 1024;
export const THUMBNAIL_MAX_SIDE = 4000;
export const THUMBNAIL_ACCEPT = "image/jpeg,image/png,image/webp";
export const THUMBNAIL_FORMAT_ERROR = "Ảnh phải là JPG/PNG/WebP, không nhận SVG hoặc GIF.";
export const THUMBNAIL_SIZE_ERROR = "Ảnh tối đa 2 MB.";
export const THUMBNAIL_DIMENSION_ERROR = `Ảnh tối đa ${THUMBNAIL_MAX_SIDE}x${THUMBNAIL_MAX_SIDE} px.`;

function readHead(file: Blob, length = 12): Promise<Uint8Array> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(reader.error);
    reader.onload = () => resolve(new Uint8Array(reader.result as ArrayBuffer));
    reader.readAsArrayBuffer(file.slice(0, length));
  });
}

/** Nhận diện định dạng theo byte đầu (không tin đuôi file/MIME do trình duyệt đoán). */
export function sniffImageType(head: Uint8Array): "jpeg" | "png" | "webp" | null {
  if (head.length >= 3 && head[0] === 0xff && head[1] === 0xd8 && head[2] === 0xff) return "jpeg";
  if (head.length >= 8 && head[0] === 0x89 && head[1] === 0x50 && head[2] === 0x4e && head[3] === 0x47) return "png";
  const ascii = (from: number, to: number) => String.fromCharCode(...Array.from(head.slice(from, to)));
  if (head.length >= 12 && ascii(0, 4) === "RIFF" && ascii(8, 12) === "WEBP") return "webp";
  return null;
}

/** Kiểm sơ bộ ở client: dung lượng + định dạng thật. Trả thông điệp lỗi hoặc null. Server vẫn kiểm lại (nguồn sự thật). */
export async function checkImageFile(file: File): Promise<string | null> {
  if (file.size === 0) return THUMBNAIL_FORMAT_ERROR;
  if (file.size > THUMBNAIL_MAX_BYTES) return THUMBNAIL_SIZE_ERROR;
  try {
    return sniffImageType(await readHead(file)) ? null : THUMBNAIL_FORMAT_ERROR;
  } catch {
    return "Không đọc được tệp ảnh. Vui lòng chọn ảnh khác.";
  }
}

export function checkImageDimensions(width: number, height: number): string | null {
  return width > THUMBNAIL_MAX_SIDE || height > THUMBNAIL_MAX_SIDE ? THUMBNAIL_DIMENSION_ERROR : null;
}
