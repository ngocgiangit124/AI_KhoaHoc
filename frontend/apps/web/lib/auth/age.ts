/** Ngày hôm nay (YYYY-MM-DD) theo giờ Việt Nam — ép `timeZone` để server/trình duyệt ra cùng kết quả. */
export function todayInVietnam(now: Date = new Date()): string {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Ho_Chi_Minh",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(now);
}

const DATE_RE = /^(\d{4})-(\d{2})-(\d{2})$/;

/** `true` nếu chuỗi đúng dạng YYYY-MM-DD và là ngày có thật trên lịch. */
export function isValidIsoDate(value: string): boolean {
  const m = DATE_RE.exec(value);
  if (!m) return false;
  const [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])];
  const date = new Date(Date.UTC(y, mo - 1, d));
  return date.getUTCFullYear() === y && date.getUTCMonth() === mo - 1 && date.getUTCDate() === d;
}

/** Tuổi tròn tại `today` (cả hai YYYY-MM-DD). `null` nếu ngày sinh không hợp lệ hoặc ở tương lai. */
export function ageOn(dateOfBirth: string, today: string): number | null {
  if (!isValidIsoDate(dateOfBirth) || !isValidIsoDate(today)) return null;
  if (dateOfBirth > today) return null;
  const [by, bm, bd] = dateOfBirth.split("-").map(Number) as [number, number, number];
  const [ty, tm, td] = today.split("-").map(Number) as [number, number, number];
  let age = ty - by;
  if (tm < bm || (tm === bm && td < bd)) age -= 1;
  return age;
}

/** Dưới ngưỡng tuổi cần phụ huynh (`parent_consent_age` từ /config/public). Ngày không hợp lệ → `false`. */
export function isBelowConsentAge(dateOfBirth: string, consentAge: number, today: string = todayInVietnam()): boolean {
  const age = ageOn(dateOfBirth, today);
  return age !== null && age < consentAge;
}
