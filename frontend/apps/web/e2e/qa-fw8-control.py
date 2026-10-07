#!/usr/bin/env python3
"""Máy chủ điều khiển dữ liệu cho e2e QA FW8 (chạy trên HOST, chỉ lắng nghe 127.0.0.1/docker). Spec trong container Playwright gọi
http://host.docker.internal:8099/<hành-động> để đổi dữ liệu `e2e-fw8-*` giữa các lần đo (rút đồng ý, khoá, ngừng bán...).
Danh sách hành động cố định, chỉ đụng bản ghi tiền tố e2e-fw8. Dùng bởi e2e/run-home-qa.sh."""
import json, subprocess, sys
from http.server import BaseHTTPRequestHandler, HTTPServer

ROOT = __file__.rsplit("/frontend/", 1)[0]
HEAD = 'use Illuminate\\Support\\Facades\\DB; $uid = fn($n) => DB::table("users")->where("email", "e2e-fw8-$n@example.com")->value("id");'
ACTIONS = {
    "ids": 'echo "JSON:", json_encode(DB::table("users")->where("email","like","e2e-fw8-%")->pluck("id","email"));',
    "withdraw_gv2": 'DB::table("teacher_profiles")->where("user_id",$uid("gv2"))->update(["public_consent_at"=>null,"public_consent_withdrawn_at"=>now()]); echo "ok";',
    "restore_gv2": 'DB::table("teacher_profiles")->where("user_id",$uid("gv2"))->update(["public_consent_at"=>now(),"public_consent_withdrawn_at"=>null]); echo "ok";',
    "lock_gv7": 'DB::table("users")->where("id",$uid("gv7"))->update(["status"=>"locked"]); echo "ok";',
    "unlock_gv7": 'DB::table("users")->where("id",$uid("gv7"))->update(["status"=>"active"]); echo "ok";',
    "unpub_gv7course": 'DB::table("courses")->where("slug","e2e-fw8-khoa-gv7")->update(["status"=>"unpublished"]); echo "ok";',
    "pub_gv7course": 'DB::table("courses")->where("slug","e2e-fw8-khoa-gv7")->update(["status"=>"published"]); echo "ok";',
    "unpub_k3": 'DB::table("courses")->where("slug","e2e-fw8-khoa-3")->update(["status"=>"unpublished"]); echo "ok";',
    "pub_k3": 'DB::table("courses")->where("slug","e2e-fw8-khoa-3")->update(["status"=>"published"]); echo "ok";',
    "avatar_on": 'foreach (["gv1","gv2","gv3","gv7"] as $n) DB::table("teacher_profiles")->where("user_id",$uid($n))->update(["avatar_path"=>"qa-fw8-avatar.png"]); echo "ok";',
    "avatar_off": 'foreach (["gv1","gv2","gv3","gv7"] as $n) DB::table("teacher_profiles")->where("user_id",$uid($n))->update(["avatar_path"=>"qa-fw8-missing.png"]); echo "ok";',
}

class H(BaseHTTPRequestHandler):
    def do_GET(self):
        name = self.path.strip("/")
        if name not in ACTIONS:
            self.send_response(404); self.end_headers(); return
        r = subprocess.run(["docker", "compose", "exec", "-T", "php", "php", "artisan", "tinker", "--execute=" + HEAD + ACTIONS[name]],
                           cwd=ROOT + "/infra", capture_output=True, text=True, timeout=120)
        self.send_response(200 if r.returncode == 0 else 500)
        self.end_headers()
        self.wfile.write((r.stdout + r.stderr).encode())
    def log_message(self, *a): pass

HTTPServer(("0.0.0.0", 8099), H).serve_forever()
