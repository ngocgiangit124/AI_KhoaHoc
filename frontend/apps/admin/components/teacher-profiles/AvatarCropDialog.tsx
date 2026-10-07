"use client";

import { useEffect, useRef, useState, type KeyboardEvent, type PointerEvent } from "react";
import { Alert, Button, Dialog } from "@vitaminvui/ui/v2";
import {
  clampPos,
  coverScale,
  CROP_MAX_ZOOM,
  CROP_MIN_ZOOM,
  CROP_VIEW,
  cropRect,
  outputSide,
  type Point,
  type Size,
} from "@/lib/teacher-profiles/crop";

export interface AvatarCropDialogProps {
  /** Ảnh người dùng vừa chọn dưới dạng data URL (CSP admin không cho `blob:` ở img-src). */
  src: string;
  onClose: () => void;
  /** Ảnh đã cắt vuông (JPEG, cạnh ≤ 800 px). */
  onDone: (blob: Blob) => void;
}

/**
 * Bước cắt khung vuông 1:1 trước khi tải lên (US-020 BR7). Kéo (chuột/chạm) hoặc phím mũi tên khi khung đang được chọn;
 * thanh trượt để phóng to. Xuất vùng cắt bằng canvas; backend vẫn mã hoá lại WebP ≤ 800 px, bỏ EXIF.
 */
export function AvatarCropDialog({ src, onClose, onDone }: AvatarCropDialogProps) {
  const [nat, setNat] = useState<Size | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [zoom, setZoom] = useState(1.2);
  const [pos, setPos] = useState<Point>({ x: 0, y: 0 });
  const [busy, setBusy] = useState(false);
  const [exportError, setExportError] = useState<string | null>(null);
  const imgRef = useRef<HTMLImageElement | null>(null);
  const frameRef = useRef<HTMLDivElement | null>(null);
  // Kích thước khung thật (co lại ở màn rất hẹp); toán cắt dùng đúng số này.
  const [view, setView] = useState(CROP_VIEW);
  const drag = useRef<{ x: number; y: number; px: number; py: number } | null>(null);

  useEffect(() => {
    const img = new Image();
    img.onload = () => {
      imgRef.current = img;
      setNat({ width: img.naturalWidth, height: img.naturalHeight });
    };
    img.onerror = () => setLoadError(true);
    img.src = src;
  }, [src]);

  useEffect(() => {
    const el = frameRef.current;
    if (!el || typeof ResizeObserver === "undefined") return;
    const measure = () => {
      const w = Math.round(el.clientWidth);
      if (w > 0) setView(w);
    };
    measure();
    const ro = new ResizeObserver(measure);
    ro.observe(el);
    return () => ro.disconnect();
  }, [nat]);

  const move = (p: Point, z = zoom) => (nat ? setPos(clampPos(p, nat, view, z)) : undefined);

  function onPointerDown(e: PointerEvent<HTMLDivElement>) {
    e.currentTarget.setPointerCapture(e.pointerId);
    drag.current = { x: e.clientX, y: e.clientY, px: pos.x, py: pos.y };
  }
  function onPointerMove(e: PointerEvent<HTMLDivElement>) {
    if (!drag.current) return;
    move({ x: drag.current.px + e.clientX - drag.current.x, y: drag.current.py + e.clientY - drag.current.y });
  }
  function onKeyDown(e: KeyboardEvent<HTMLDivElement>) {
    const step = e.shiftKey ? 20 : 5;
    const d = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[e.key];
    if (!d) return;
    e.preventDefault();
    move({ x: pos.x + (d[0] ?? 0), y: pos.y + (d[1] ?? 0) });
  }
  function onZoom(next: number) {
    setZoom(next);
    move(pos, next);
  }

  function confirm() {
    const img = imgRef.current;
    if (!nat || !img) return;
    setBusy(true);
    setExportError(null);
    const rect = cropRect(nat, view, zoom, pos);
    const side = outputSide(rect);
    const canvas = document.createElement("canvas");
    canvas.width = side;
    canvas.height = side;
    const ctx = canvas.getContext("2d");
    if (!ctx) {
      setBusy(false);
      setExportError("Trình duyệt không cắt được ảnh. Vui lòng thử trình duyệt khác.");
      return;
    }
    ctx.fillStyle = "#fff"; // ảnh PNG trong suốt → nền trắng khi lưu JPEG
    ctx.fillRect(0, 0, side, side);
    ctx.drawImage(img, rect.sx, rect.sy, rect.size, rect.size, 0, 0, side, side);
    canvas.toBlob(
      (blob) => {
        setBusy(false);
        if (blob) onDone(blob);
        else setExportError("Không xuất được ảnh đã cắt. Vui lòng chọn ảnh khác.");
      },
      "image/jpeg",
      0.92,
    );
  }

  const s = nat ? coverScale(nat, view) : 1;
  const dw = nat ? nat.width * s : view;
  const dh = nat ? nat.height * s : view;

  return (
    <Dialog
      open
      onClose={onClose}
      title="Cắt ảnh đại diện"
      description="Ảnh hiển thị dạng vuông. Kéo để chọn phần khuôn mặt, dùng thanh trượt để phóng to."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Huỷ
          </Button>
          <Button onClick={confirm} disabled={!nat} loading={busy} loadingText="Đang cắt…">
            Dùng ảnh này
          </Button>
        </>
      }
    >
      <div className="flex flex-col items-center gap-4">
        {loadError ? (
          <Alert tone="danger" title="Không đọc được ảnh">
            Vui lòng đóng hộp thoại và chọn ảnh JPG, PNG hoặc WebP khác.
          </Alert>
        ) : (
          <div
            role="application"
            aria-label="Vùng cắt ảnh. Dùng phím mũi tên để di chuyển ảnh."
            tabIndex={0}
            data-testid="crop-area"
            onKeyDown={onKeyDown}
            onPointerDown={onPointerDown}
            onPointerMove={onPointerMove}
            onPointerUp={() => (drag.current = null)}
            onPointerCancel={() => (drag.current = null)}
            onLostPointerCapture={() => (drag.current = null)}
            ref={frameRef}
            className="focus-ring relative cursor-grab touch-none overflow-hidden rounded-card bg-player active:cursor-grabbing"
            style={{ width: `min(${CROP_VIEW}px, 100%)`, aspectRatio: "1 / 1" }}
          >
            {nat ? (
              // eslint-disable-next-line @next/next/no-img-element -- data: URL tạm để cắt, không qua trình tối ưu ảnh
              <img
                src={src}
                alt=""
                draggable={false}
                className="pointer-events-none absolute max-w-none select-none"
                style={{ width: dw, height: dh, left: (view - dw) / 2, top: (view - dh) / 2, transform: `translate(${pos.x}px, ${pos.y}px) scale(${zoom})` }}
              />
            ) : null}
            {/* Lưới 3×3 giúp căn khuôn mặt; khung vuông = vùng được giữ lại. */}
            <div aria-hidden="true" className="pointer-events-none absolute inset-0 grid grid-cols-3 grid-rows-3 border-2 border-player-ink">
              {Array.from({ length: 9 }).map((_, i) => (
                <span key={i} className="border border-player-ink/40" />
              ))}
            </div>
          </div>
        )}
        <label className="flex w-full max-w-xs flex-col gap-1 text-sm font-semibold text-ink">
          Phóng to
          <input
            type="range"
            min={CROP_MIN_ZOOM}
            max={CROP_MAX_ZOOM}
            step={0.05}
            value={zoom}
            disabled={!nat}
            onChange={(e) => onZoom(Number(e.target.value))}
            className="focus-ring h-11 accent-primary"
          />
        </label>
        {exportError ? (
          <Alert tone="danger" title={exportError} />
        ) : null}
      </div>
    </Dialog>
  );
}
