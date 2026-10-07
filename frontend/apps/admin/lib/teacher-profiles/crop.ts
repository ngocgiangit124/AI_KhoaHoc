export interface Size {
  width: number;
  height: number;
}
export interface Point {
  x: number;
  y: number;
}

export const CROP_VIEW = 280;
export const CROP_MIN_ZOOM = 1;
export const CROP_MAX_ZOOM = 3;
export const AVATAR_OUTPUT_MAX = 800;

/**
 * Bước cắt ảnh vuông 1:1 (US-020 BR7). Ảnh gốc được phủ kín khung vuông `view` (cover, ở zoom = 1), căn giữa; người dùng
 * phóng to (`zoom`) và kéo (`pos`, px trong khung). Các hàm thuần để test mà không cần canvas.
 */
export function coverScale(nat: Size, view: number): number {
  return Math.max(view / nat.width, view / nat.height);
}

/** Giới hạn dịch chuyển để khung luôn nằm trong ảnh (không lộ vùng trống). */
export function clampPos(pos: Point, nat: Size, view: number, zoom: number): Point {
  const s = coverScale(nat, view) * zoom;
  const maxX = Math.max(0, (nat.width * s - view) / 2);
  const maxY = Math.max(0, (nat.height * s - view) / 2);
  return { x: Math.min(maxX, Math.max(-maxX, pos.x)) + 0, y: Math.min(maxY, Math.max(-maxY, pos.y)) + 0 };
}

export interface CropRect {
  sx: number;
  sy: number;
  size: number;
}

/** Vùng vuông của ảnh gốc (px ảnh) đang nằm trong khung. */
export function cropRect(nat: Size, view: number, zoom: number, pos: Point): CropRect {
  const p = clampPos(pos, nat, view, zoom);
  const s = coverScale(nat, view) * zoom;
  const size = view / s;
  const sx = nat.width / 2 - (view / 2 + p.x) / s;
  const sy = nat.height / 2 - (view / 2 + p.y) / s;
  const max = Math.min(nat.width, nat.height);
  const clampedSize = Math.min(size, max);
  return {
    sx: Math.min(nat.width - clampedSize, Math.max(0, sx)),
    sy: Math.min(nat.height - clampedSize, Math.max(0, sy)),
    size: clampedSize,
  };
}

/** Cạnh ảnh xuất: không phóng to, tối đa 800 px (backend vẫn mã hoá lại WebP ≤ 800). */
export function outputSide(rect: CropRect): number {
  return Math.max(1, Math.min(AVATAR_OUTPUT_MAX, Math.round(rect.size)));
}
