#!/bin/sh
# Kiểm ACL của worker-video trên staging/production (R1, C4-M1). Dùng: check-acl.sh <host> <port> <redis_prefix> <cache_prefix> <worker_user> <worker_password>
# Ví dụ: check-acl.sh 10.0.0.5 6379 vitaminvui-database- vitaminvui_cache vv_worker_video "$WORKER_PW"
# Cần redis-cli. Mật khẩu truyền qua biến môi trường REDISCLI_AUTH để không lộ trong danh sách tiến trình. Thoát 0 nếu mọi kiểm đều đạt.
set -u
host=$1; port=$2; prefix=$3; cprefix=$4; user=$5; REDISCLI_AUTH=$6; export REDISCLI_AUTH
fail=0
rc() { redis-cli --no-auth-warning -h "$host" -p "$port" --user "$user" "$@" 2>&1; }

expect_noperm() {
    out=$(rc "$@")
    case "$out" in *NOPERM*|*"no permissions"*|*"ACL failure"*) echo "OK    cấm: $*" ;; *) echo "FAIL  phải bị NOPERM: $* -> $out"; fail=1 ;; esac
}
expect_ok() {
    out=$(rc "$@")
    case "$out" in *NOPERM*|*ERR*|*"no permissions"*) echo "FAIL  phải chạy được: $* -> $out"; fail=1 ;; *) echo "OK    cho phép: $*" ;; esac
}

[ "$(rc ping)" = "PONG" ] || { echo "FAIL  không đăng nhập được bằng user $user"; exit 1; }
expect_noperm -n 1 get "${prefix}${cprefix}anysessionid"
expect_noperm -n 1 --scan
expect_noperm -n 3 lpush "${prefix}queues:default" x
expect_noperm -n 3 rpush "${prefix}queues:default" x
expect_noperm -n 3 eval "return redis.call('rpush', KEYS[1], 'x')" 1 "${prefix}queues:default"
expect_noperm -n 3 eval "return redis.call('get', '${prefix}${cprefix}anysessionid')" 0
expect_noperm -n 3 set "${prefix}queues:video" x
expect_noperm -n 3 del "${prefix}queues:video"
expect_noperm flushall
expect_noperm config get '*'
expect_noperm keys '*'
expect_ok -n 3 llen "${prefix}queues:video"
expect_ok -n 3 zcard "${prefix}queues:video:reserved"
expect_ok -n 3 eval "return redis.call('llen', KEYS[1])" 1 "${prefix}queues:video"
# Khoá tín hiệu (chỉ khi dùng chung Redis với app; instance riêng cho video thì bỏ qua dòng dưới bằng biến SKIP_SIGNALS=1).
if [ "${SKIP_SIGNALS:-0}" != 1 ]; then
    expect_ok -n 2 get "${prefix}${cprefix}illuminate:queue:restart"
    expect_noperm -n 2 set "${prefix}${cprefix}illuminate:queue:restart" 1
fi
[ $fail -eq 0 ] && echo "ACL đạt." || echo "ACL KHÔNG đạt."
exit $fail
