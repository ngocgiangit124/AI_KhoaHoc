#!/usr/bin/env bash
# Chuẩn bị/dọn dữ liệu cho e2e/nhat-ky-real.spec.ts (FA12, US-016). Idempotent, chạy trên máy host, cần Docker local đang chạy:
#   frontend/apps/admin/e2e/seed-e2e-fa12.sh            # tạo (chưa có thì tạo) tài khoản staff e2e-fa12-*
#   frontend/apps/admin/e2e/seed-e2e-fa12.sh --reset    # dọn rồi tạo lại (dùng trước MỖI lần chạy spec)
#   frontend/apps/admin/e2e/seed-e2e-fa12.sh --clean    # dọn: xoá tài khoản staff e2e-fa12-*
# Chỉ chạy ở APP_ENV local/testing, DB `vitaminvui`, APP_URL chứa localhost. Chỉ dùng tinker (không migrate/seed). Mật khẩu: `Password123!`.
# Tài khoản: e2e-fa12-admin1 Admin (MFA qua Mailpit)  e2e-fa12-qlt1 Quản lý trang (MFA; để thử 403)  e2e-fa12-gv1 giáo viên (đối tượng khóa/mở khóa tạo log).
# LƯU Ý: `audit_logs` bất biến (trigger DB) nên các dòng nhật ký do spec tạo KHÔNG xoá được; chúng chỉ trỏ tới tài khoản e2e-fa12-* đã xoá.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
cd "$ROOT/infra"

ENV_NOW="$(docker compose exec -T php php -r 'echo getenv("APP_ENV");' 2>/dev/null | tr -d '\r')"
case "$ENV_NOW" in local|testing) ;; *) echo "Từ chối: APP_ENV='$ENV_NOW' (chỉ local/testing)." >&2; exit 1 ;; esac

# Chốt chặn thứ hai: chỉ chạy trên DB dev local `vitaminvui` và APP_URL localhost.
DB_NOW="$(docker compose exec -T php php -r 'echo getenv("DB_DATABASE");' 2>/dev/null | tr -d '\r')"
URL_NOW="$(docker compose exec -T php php -r 'echo getenv("APP_URL");' 2>/dev/null | tr -d '\r')"
if [ "$DB_NOW" != "vitaminvui" ] || ! echo "$URL_NOW" | grep -q "localhost"; then
  echo "Từ chối: DB_DATABASE='$DB_NOW', APP_URL='$URL_NOW' (cần DB 'vitaminvui' và APP_URL chứa 'localhost')." >&2; exit 1
fi

MODE="${1:-}"
case "$MODE" in ""|--reset|--clean) ;; *) echo "Dùng: $0 [--reset|--clean]" >&2; exit 2 ;; esac

CLEAN_PHP='
use Illuminate\Support\Facades\DB;
$users = App\Models\User::where("email", "like", "e2e-fa12-%")->pluck("id");
DB::transaction(function () use ($users) {
  DB::table("staff_devices")->whereIn("user_id", $users)->delete();
  App\Models\User::whereIn("id", $users)->delete();
});
echo "da don ", count($users), " tai khoan";'

SEED_PHP='
use App\Models\User;
use Illuminate\Support\Facades\Hash;
$pw = Hash::make("Password123!");
$mk = fn ($e, $name, $state) => User::where("email", "e2e-fa12-$e@example.com")->first()
  ?? User::factory()->$state()->create(["email" => "e2e-fa12-$e@example.com", "name" => $name, "password" => $pw]);
$mk("admin1", "E2E FA12 Admin", "admin");
$mk("qlt1", "E2E FA12 QLT", "pageManager");
$mk("gv1", "E2E FA12 GV", "teacher");
echo "seed ok: ", User::where("email", "like", "e2e-fa12-%")->count(), " tai khoan";'

if [ "$MODE" = "--clean" ] || [ "$MODE" = "--reset" ]; then
  docker compose exec -T php php artisan tinker --execute="$CLEAN_PHP"
  echo
fi
if [ "$MODE" != "--clean" ]; then
  docker compose exec -T php php artisan tinker --execute="$SEED_PHP"
  echo
  echo "Nhắc: chạy xong nhớ dọn bằng: $0 --clean" >&2
fi
