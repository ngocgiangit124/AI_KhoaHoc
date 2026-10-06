import { IconPlay, ProgressBar } from "@vitaminvui/ui/v2";

/**
 * Hình minh hoạ hero: một bài học thật đang xem dở (khung video vẽ hình góc nội tiếp + tiến độ)
 * và một dòng chú thích viết tay. Hoàn toàn trang trí (aria-hidden): số bài/thời lượng trong hình không phải lời khẳng định (US-019 BR5).
 */
export function LessonPeek() {
  return (
    <figure aria-hidden="true" className="relative mx-auto w-full max-w-md lg:max-w-none">
      <div className="overflow-hidden rounded-sheet border border-line bg-surface p-3 shadow-raised">
        <div className="relative aspect-video overflow-hidden rounded-card bg-player">
          <svg viewBox="0 0 320 180" className="absolute inset-0 size-full" aria-hidden="true">
            <circle cx="120" cy="92" r="62" fill="none" stroke="white" strokeOpacity="0.85" strokeWidth="2" />
            <circle cx="120" cy="92" r="2.5" fill="white" />
            <polyline points="120,30 66,122 174,122 120,30" fill="none" stroke="white" strokeOpacity="0.85" strokeWidth="2" />
            <path d="M111 45 Q120 52 129 45" fill="none" className="stroke-accent" strokeWidth="2.5" />
            <text x="114" y="24" fill="white" fontSize="11" fontFamily="sans-serif">A</text>
            <text x="54" y="134" fill="white" fontSize="11" fontFamily="sans-serif">B</text>
            <text x="178" y="134" fill="white" fontSize="11" fontFamily="sans-serif">C</text>
            <text x="206" y="74" fill="white" fontSize="13" fontFamily="serif" fontStyle="italic">∠BAC = ½ sđ BC</text>
          </svg>
          <span className="absolute left-1/2 top-1/2 flex size-14 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-surface text-primary shadow-overlay">
            <IconPlay size={22} className="translate-x-0.5" />
          </span>
          <span className="num absolute bottom-2 right-2 rounded bg-player/80 px-1.5 py-0.5 text-xs font-semibold text-player-ink">12:40</span>
        </div>
        <figcaption className="flex flex-col gap-2 px-1 pb-1 pt-3">
          <span className="text-sm font-medium text-ink-soft">Hình học 9 · Chương 2</span>
          <span className="text-base font-semibold text-ink">Bài 7. Góc nội tiếp</span>
          <ProgressBar value={37} label="Tiến độ khóa" valueText="6/16 bài" size="sm" hideLabel />
          <span className="num self-end text-sm font-semibold text-ink-soft">6/16 bài</span>
        </figcaption>
      </div>
      <p aria-hidden="true" className="mt-3 -rotate-2 text-right font-hand text-lg text-primary sm:text-xl">
        Hiểu rồi! Làm tiếp bài 8 nhé ✓
      </p>
    </figure>
  );
}
