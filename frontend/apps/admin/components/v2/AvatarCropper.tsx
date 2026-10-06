"use client";

import Image from "next/image";
import { useRef, useState, type KeyboardEvent, type PointerEvent } from "react";
import { Button, Dialog } from "@vitaminvui/ui/v2";

const VIEW = 280;

/**
 * Bước cắt khung vuông 1:1 khi tải ảnh (US-020 BR7). Kéo ảnh (chuột/chạm) hoặc dùng phím mũi tên khi khung
 * đang được chọn; thanh trượt để phóng to. Bản thật: xuất vùng cắt (canvas) rồi gửi lên; backend vẫn mã hoá
 * lại WebP ≤ 800px. Ảnh xem trước dùng data URL (CSP admin không cho `blob:` ở img-src).
 */
export function AvatarCropper({ open, src, onClose, onDone }: { open: boolean; src: string | null; onClose: () => void; onDone: () => void }) {
  const [zoom, setZoom] = useState(1.2);
  const [pos, setPos] = useState({ x: 0, y: 0 });
  const drag = useRef<{ x: number; y: number; px: number; py: number } | null>(null);

  function onPointerDown(e: PointerEvent<HTMLDivElement>) {
    e.currentTarget.setPointerCapture(e.pointerId);
    drag.current = { x: e.clientX, y: e.clientY, px: pos.x, py: pos.y };
  }
  function onPointerMove(e: PointerEvent<HTMLDivElement>) {
    if (!drag.current) return;
    setPos({ x: drag.current.px + e.clientX - drag.current.x, y: drag.current.py + e.clientY - drag.current.y });
  }
  function onKeyDown(e: KeyboardEvent<HTMLDivElement>) {
    const step = e.shiftKey ? 20 : 5;
    const d = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[e.key];
    if (!d) return;
    e.preventDefault();
    setPos((p) => ({ x: p.x + (d[0] ?? 0), y: p.y + (d[1] ?? 0) }));
  }

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title="Cắt ảnh đại diện"
      description="Ảnh hiển thị dạng vuông. Kéo để chọn phần khuôn mặt, dùng thanh trượt để phóng to."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Huỷ
          </Button>
          <Button onClick={onDone}>Dùng ảnh này</Button>
        </>
      }
    >
      <div className="flex flex-col items-center gap-4">
        <div
          role="application"
          aria-label="Vùng cắt ảnh. Dùng phím mũi tên để di chuyển ảnh."
          tabIndex={0}
          onKeyDown={onKeyDown}
          onPointerDown={onPointerDown}
          onPointerMove={onPointerMove}
          onPointerUp={() => (drag.current = null)}
          className="focus-ring relative cursor-grab touch-none overflow-hidden rounded-card bg-player active:cursor-grabbing"
          style={{ width: VIEW, height: VIEW }}
        >
          {src ? (
            <div className="absolute inset-0" style={{ transform: `translate(${pos.x}px, ${pos.y}px) scale(${zoom})` }}>
              <Image src={src} alt="" fill unoptimized draggable={false} className="pointer-events-none select-none object-cover" />
            </div>
          ) : null}
          {/* Lưới 3×3 giúp căn khuôn mặt; khung vuông = vùng được giữ lại. */}
          <div aria-hidden="true" className="pointer-events-none absolute inset-0 grid grid-cols-3 grid-rows-3 border-2 border-player-ink">
            {Array.from({ length: 9 }).map((_, i) => (
              <span key={i} className="border border-player-ink/40" />
            ))}
          </div>
        </div>
        <label className="flex w-full max-w-xs flex-col gap-1 text-sm font-semibold text-ink">
          Phóng to
          <input type="range" min={1} max={3} step={0.05} value={zoom} onChange={(e) => setZoom(Number(e.target.value))} className="focus-ring h-6 accent-primary" />
        </label>
      </div>
    </Dialog>
  );
}
