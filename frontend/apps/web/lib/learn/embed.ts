/**
 * Link ngoài (YouTube/Vimeo): backend trả `kind: 'embed'` với URL dựng lại từ ID. Vẫn kiểm host ở đây (khớp `frame-src` của CSP
 * trong `proxy.ts`) và chỉ cho https — phòng thủ nhiều lớp, không tin tuyệt đối vào response.
 */
const EMBED_HOSTS = new Set(["www.youtube-nocookie.com", "player.vimeo.com"]);

export function isAllowedEmbedUrl(raw: string): boolean {
  try {
    const url = new URL(raw);
    return url.protocol === "https:" && EMBED_HOSTS.has(url.hostname);
  } catch {
    return false;
  }
}

/** Cách ly iframe: chạy được script của trình phát nhưng không điều hướng trang chủ, không mở popup, không gửi form. */
export const EMBED_SANDBOX = "allow-scripts allow-same-origin allow-presentation";
