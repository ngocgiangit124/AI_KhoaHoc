/** Số giây còn lại (làm tròn lên, ≥ 0) tới mốc ISO `until`; mốc không đọc được → 0. */
export function secondsUntil(until: string | null | undefined, now: number = Date.now()): number {
  if (!until) return 0;
  const t = Date.parse(until);
  if (Number.isNaN(t)) return 0;
  return Math.max(0, Math.ceil((t - now) / 1000));
}

/** `mm:ss`. */
export function formatCountdown(seconds: number): string {
  const s = Math.max(0, Math.floor(seconds));
  return `${String(Math.floor(s / 60)).padStart(2, "0")}:${String(s % 60).padStart(2, "0")}`;
}
