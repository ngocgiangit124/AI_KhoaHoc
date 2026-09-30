<?php

namespace App\Services\Content;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Sanitize HTML cho `courses.description` (api-contract §4, S8) — profile
 * "course_description":
 *   - `HTML.Allowed = p,br,strong,em,u,ul,ol,li,h2,h3,h4,blockquote,a[href]`
 *   - `URI.AllowedSchemes = http,https,mailto`
 *   - `HTML.TargetBlank = true`, `HTML.Nofollow = true`
 *     (ép `rel="nofollow noopener noreferrer"`)
 *   - Không cho `img`, `iframe`, `style`, `class`, `on*`.
 *
 * **QUAN TRỌNG — lệch khỏi kế hoạch G2:** `docs/architecture/tasks.md` duyệt
 * `mews/purifier` cho task này (dự phòng `ezyang/htmlpurifier` nếu không
 * tương thích Laravel 13). Khi hiện thực T08, `composer require` cho CẢ 2
 * package này đều bị **sandbox của agent chặn thẳng** ("Untrusted Code
 * Integration" — không phải lỗi tương thích, không phải lỗi mạng — thử lại
 * đúng 1 lần cho mỗi package rồi dừng theo đúng quy tắc an toàn). Vì đây là
 * yêu cầu BẮT BUỘC (S8, có test XSS trong AC), lớp này tạm hiện thực bằng
 * `ext-dom` (luôn có sẵn trong PHP, KHÔNG cần cài thêm gì) thay vì dùng thư
 * viện đã qua kiểm chứng rộng rãi.
 *
 * **`laravel-security` PHẢI soi kỹ lớp này** — tự viết sanitizer luôn rủi ro
 * hơn HTMLPurifier (đã chống hàng trăm mutation-XSS/edge-case libxml qua
 * nhiều năm). Đề xuất: ngay khi có người ngoài sandbox này chạy được
 * `composer require mews/purifier` (hoặc `ezyang/htmlpurifier`), thay TOÀN BỘ
 * phần thân `sanitize()` bằng lệnh gọi thư viện đó — chữ ký hàm
 * (`sanitize(?string): ?string`) giữ nguyên nên không phải sửa nơi gọi
 * (`CourseService`, `CourseResource`).
 *
 * Chiến lược: allowlist thẻ NGHIÊM NGẶT.
 * - Thẻ nằm trong {@see ALLOWED_TAGS}: giữ thẻ, xoá MỌI thuộc tính (kể cả
 *   `class`/`style`/`on*`) — riêng `<a>` được giữ lại `href` sau khi kiểm tra
 *   scheme, và luôn bị ép `target="_blank" rel="nofollow noopener noreferrer"`.
 * - Thẻ nằm trong {@see DROP_WITH_CONTENT_TAGS} (`script`, `style`, `iframe`,
 *   `svg`,...): xoá CẢ THẺ LẪN NỘI DUNG bên trong (không đệ quy vào con).
 * - Thẻ khác (`div`, `span`, `img`, `table`,...): "unwrap" — bỏ thẻ, giữ lại
 *   nội dung con đã lọc đệ quy (khớp hành vi mặc định của HTMLPurifier với
 *   thẻ ngoài `HTML.Allowed`). `img` không có `children` nên bị loại bỏ hoàn
 *   toàn theo đúng yêu cầu "không cho img".
 * - Comment, CDATA, processing instruction: luôn bỏ.
 */
class HtmlSanitizer
{
    /**
     * @var list<string>
     */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'em', 'u', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'blockquote', 'a',
    ];

    /**
     * Thẻ bị xoá CẢ thẻ lẫn nội dung bên trong — nội dung của các thẻ này
     * không phải văn bản hiển thị thông thường, hoặc bản thân thẻ luôn là
     * vector XSS đã biết (script/style/iframe/svg/math/form...).
     *
     * @var list<string>
     */
    private const DROP_WITH_CONTENT_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math',
        'template', 'noscript', 'form', 'noframes', 'applet', 'link', 'meta', 'head', 'title',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_URI_SCHEMES = ['http', 'https', 'mailto'];

    public function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        if (trim($html) === '') {
            return '';
        }

        // R7 — chuẩn hoá về UTF-8 hợp lệ (byte sai thành `?`): libxml gặp
        // byte UTF-8 sai sẽ đoán lại encoding và làm hỏng (mojibake) cả văn
        // bản tiếng Việt còn lại.
        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');

        $document = new DOMDocument;

        // Bọc trong <body> khai `meta charset="utf-8"` để libxml không tự
        // đoán encoding (mặc định ISO-8859-1 nếu thiếu khai báo — làm hỏng
        // tiếng Việt có dấu). `LIBXML_NOERROR|LIBXML_NOWARNING` bỏ qua cảnh
        // báo HTML không well-formed (input người dùng không đảm bảo chuẩn);
        // `LIBXML_NONET` chặn tải tài nguyên ngoài mạng (phòng xa XXE/SSRF dù
        // parser HTML của libxml không tải DTD ngoài theo mặc định).
        $wrapped = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>';

        $loaded = @$document->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);

        if (! $loaded) {
            // Không parse được (input hỏng nặng) — an toàn nhất là bỏ trắng
            // thay vì giữ nguyên HTML thô chưa qua lọc.
            return '';
        }

        $body = $document->getElementsByTagName('body')->item(0);

        if ($body === null) {
            return '';
        }

        $output = '';

        foreach (iterator_to_array($body->childNodes) as $child) {
            $output .= $this->renderNode($child);
        }

        return trim($output);
    }

    private function renderNode(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return htmlspecialchars($node->wholeText, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }

        if (! $node instanceof DOMElement) {
            // Comment, CDATA, processing instruction... — luôn bỏ.
            return '';
        }

        $tag = strtolower($node->tagName);

        if (in_array($tag, self::DROP_WITH_CONTENT_TAGS, true)) {
            return '';
        }

        $innerHtml = '';
        foreach (iterator_to_array($node->childNodes) as $child) {
            $innerHtml .= $this->renderNode($child);
        }

        if (! in_array($tag, self::ALLOWED_TAGS, true)) {
            // "Unwrap" — bỏ thẻ không nằm trong allowlist, giữ nội dung con
            // đã lọc. `img` (không có con) tự nhiên biến mất hoàn toàn.
            return $innerHtml;
        }

        if ($tag === 'a') {
            return $this->renderAnchor($node, $innerHtml);
        }

        if ($tag === 'br') {
            return '<br>';
        }

        // Mọi thẻ allowlist khác: KHÔNG copy bất kỳ thuộc tính gốc nào (chặn
        // `class`/`style`/`on*` tuyệt đối, không cần allowlist thuộc tính).
        return "<{$tag}>{$innerHtml}</{$tag}>";
    }

    private function renderAnchor(DOMElement $node, string $innerHtml): string
    {
        $href = $node->hasAttribute('href') ? trim($node->getAttribute('href')) : '';

        if ($href === '' || ! $this->isAllowedUri($href)) {
            // Không có href hợp lệ (thiếu, hoặc scheme không nằm trong
            // allowlist — vd `javascript:`) — bỏ hẳn thẻ `<a>`, chỉ giữ lại
            // nội dung dạng văn bản (không tạo link rỗng/nguy hiểm).
            return $innerHtml;
        }

        $safeHref = htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        // HTML.TargetBlank + HTML.Nofollow (api-contract §4) — ép cứng theo
        // cấu hình, không đọc `target`/`rel` gốc từ input.
        return '<a href="'.$safeHref.'" target="_blank" rel="nofollow noopener noreferrer">'.$innerHtml.'</a>';
    }

    /**
     * `URI.AllowedSchemes = http,https,mailto`. Đường dẫn tương đối (không có
     * scheme, ví dụ `/khoa-hoc/toan-9`) được coi là hợp lệ (cùng gốc); riêng
     * `//host/...` (protocol-relative) bị từ chối vì scheme mơ hồ (không rõ
     * http hay https, có thể trỏ ra ngoài ý muốn).
     */
    private function isAllowedUri(string $uri): bool
    {
        // R4 — trình duyệt coi `\` như `/` trong URL: `\\host` và `/\host`
        // là protocol-relative. Chuẩn hoá backslash TRƯỚC khi kiểm `//`.
        // Ký tự điều khiển (tab/newline... bị trình duyệt bỏ khi phân tích
        // scheme) → từ chối hẳn.
        if (preg_match('/[\x00-\x1F\x7F]/', $uri) === 1) {
            return false;
        }

        $uri = str_replace('\\', '/', $uri);

        if (str_starts_with($uri, '//')) {
            return false;
        }

        $colonPos = strpos($uri, ':');
        $slashPos = strpos($uri, '/');

        $hasScheme = $colonPos !== false && ($slashPos === false || $colonPos < $slashPos);

        if (! $hasScheme) {
            return true;
        }

        $scheme = strtolower(substr($uri, 0, $colonPos));

        return in_array($scheme, self::ALLOWED_URI_SCHEMES, true);
    }
}
