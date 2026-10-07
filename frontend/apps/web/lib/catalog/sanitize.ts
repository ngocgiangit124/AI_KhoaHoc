import DOMPurify from "isomorphic-dompurify";

/**
 * Allowlist trùng với HtmlSanitizer phía Laravel (config/purifier.php):
 * `p,br,strong,em,u,ul,ol,li,h2,h3,h4,blockquote,a[href]`. Lọc lần nữa ở FE vì FE không được
 * tin payload (api-contract §3: "FE vẫn DOMPurify"; S8).
 */
const ALLOWED_TAGS = ["p", "br", "strong", "em", "u", "ul", "ol", "li", "h2", "h3", "h4", "blockquote", "a"];
const ALLOWED_ATTR = ["href", "rel", "target"];

let hooked = false;
function ensureHook() {
  if (hooked) return;
  hooked = true;
  // Link do giáo viên nhập: mở tab mới, không lộ opener/referrer, không truyền uy tín SEO.
  DOMPurify.addHook("afterSanitizeAttributes", (node) => {
    if (node.tagName === "A" && node.hasAttribute("href")) {
      node.setAttribute("target", "_blank");
      node.setAttribute("rel", "noopener noreferrer nofollow ugc");
    } else if (node.tagName === "A") {
      node.removeAttribute("target");
      node.removeAttribute("rel");
    }
  });
}

/** Chỉ http(s)/mailto — chặn `javascript:`, `data:`... */
const ALLOWED_URI_REGEXP = /^(?:https?:|mailto:)/i;

export function sanitizeCourseDescription(html: string): string {
  ensureHook();
  return DOMPurify.sanitize(html, {
    ALLOWED_TAGS,
    ALLOWED_ATTR,
    ALLOWED_URI_REGEXP,
    ALLOW_DATA_ATTR: false,
    ALLOW_ARIA_ATTR: false,
  });
}
