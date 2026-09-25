---
name: docker-dev-env-gotchas
description: Bẫy khi chạy Next.js/pnpm trong Docker container non-root với bind mount — HOME env, Turbopack workspace root detection
metadata:
  type: feedback
---

**Không bao giờ đặt biến `HOME` của container trỏ vào một thư mục con nằm bên trong thư
mục workspace đang bind-mount** (ví dụ `HOME=/workspace/.home`). Next.js 16 (Turbopack)
tự phát hiện workspace root bằng cách tìm `pnpm-workspace.yaml`/lockfile đi lên từ app,
nhưng nếu phát hiện thư mục ứng viên đó "chứa" thư mục trùng với `$HOME`, nó **từ chối
dùng workspace root đó** (cảnh báo "would include your home directory") và fallback về
dùng chính thư mục app làm filesystem root — dẫn tới lỗi `Could not find the Next.js
package (next/package.json)` dù `node_modules/next` tồn tại ở workspace root, vì
Turbopack không "thấy" ra ngoài phạm vi app nữa.

**Why:** Phát hiện khi build `apps/web` trong monorepo pnpm — đặt `HOME=/workspace/.home`
(để cache pnpm store/corepack theo user host qua bind mount) làm `next build` fail hoàn
toàn, trong khi `next dev`/`typecheck`/`lint` vẫn chạy bình thường (chỉ build production
mới kích hoạt code path detect workspace root này).

**How to apply:** Dùng `HOME` sẵn có của image (`node:22` có sẵn user `node`, UID/GID
1000, `HOME=/home/node`, đã đúng quyền nếu host UID cũng 1000) — không tự tạo `.home`
trong thư mục project. Cache pnpm store (`PNPM_HOME`) vẫn có thể nằm trong workspace bind
mount bình thường (không bị heuristic này chặn, chỉ áp dụng cho biến `HOME`/`os.homedir()`).
Áp dụng cho mọi dự án Next.js 16 chạy trong Docker non-root với bind mount, không riêng
VitaminVui — [[vitaminvui-fe0-workspace]].
