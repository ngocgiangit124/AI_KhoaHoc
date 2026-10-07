/**
 * Chạy một khu vực của trang chủ: kết quả hoặc `null` khi lỗi/quá chậm — một khu vực hỏng không kéo cả trang xuống
 * (US-019 BR8/AC9, US-020 BR10). Quá hạn thì bỏ qua kết quả (request vẫn chạy nền và vào Data Cache khi xong).
 */
export const HOME_SECTION_TIMEOUT_MS = 4000;

export type Settled<T> = { ok: true; value: T } | { ok: false };

export async function settle<T>(work: Promise<T>, timeoutMs: number = HOME_SECTION_TIMEOUT_MS): Promise<Settled<T>> {
  let timer: ReturnType<typeof setTimeout> | undefined;
  const timeout = new Promise<never>((_, reject) => {
    timer = setTimeout(() => reject(new Error("home-section-timeout")), timeoutMs);
  });
  try {
    return { ok: true, value: await Promise.race([work, timeout]) };
  } catch {
    return { ok: false };
  } finally {
    clearTimeout(timer);
    // Tránh unhandledRejection khi `work` lỗi sau khi đã hết hạn.
    work.catch(() => undefined);
  }
}
