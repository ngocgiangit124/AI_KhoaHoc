# QA: Sửa lỗi nhỏ 4 (throttle catalog SSR + Nginx mẫu production)
**Kết quả:** PASS (0 bug). Phạm vi: ADR-004 §2.8, review APPROVE (`docs/review/sua-loi-nho-4.md`), chưa commit.

## Độ phủ tiêu chí xong (tasks.md)
| Tiêu chí | Test / cách kiểm | Kết quả |
|---|---|---|
| (a) token đúng, không `X-Client-IP`: request 121 vẫn 200, chạm trần tổng 130 thì 429 `TOO_MANY_ATTEMPTS` | `T26/CatalogSsrLimiterTest` (a) | PASS |
| (b) token đúng + `X-Client-IP: abc` giống (a) | (b) | PASS |
| (c) 2 IP khách, mỗi IP 120 riêng | (c) | PASS |
| (d) token sai + IP giả vẫn theo IP kết nối | (d) | PASS |
| (e) có IP và không IP ăn chung `ssr-total` | (e) | PASS |
| Nginx: ép Host ở 8081, allow + deny all, GET/HEAD `/api/v1/`, log riêng (R1) | `T31/NginxSsrInternalConfigTest` N1-N3 (mới) | PASS |
| Mọi host công khai xoá `X-Internal-Token`/`X-Client-IP` (web, admin, api, admin-api qua snippet, video) | N4 (mới) | PASS |
| Host web có `limit_req` + 429, `/_next/static/` không có | N5, N6 (mới) | PASS |
| `nginx -t` với mẫu đã thay placeholder | nginx:1.27 thật | PASS |
| Chạy thật trên Nginx mẫu | bảng dưới | PASS |

Test mới có kiểm "đột biến": gỡ `fastcgi_param HTTP_HOST` thì N1 fail; thêm `limit_req` vào `/_next/static/`, bỏ `limit_req` ở `/` hoặc bỏ xoá `X-Client-IP` thì N4/N5 fail (mount bản mẫu đã sửa đè lên, không sửa file trong repo).

## Nginx thật (nginx:1.27 + php-fpm giả in header nhận được + Next giả)
Mạng tạm 172.29.0.0/24 (nginx .10, php .11, next giả .12, curl .20 = `<IP_NEXT_SERVER>`, .21/.22 = IP khác). Đã xoá toàn bộ container và network.
| Kịch bản | Kết quả |
|---|---|
| Từ .20 gọi `http://172.29.0.10:8081/api/v1/courses`, KHÔNG đặt Host | 200, backend nhận `Host: api.vitaminvui.vn` |
| Từ .20 tự đặt `Host: admin-api...` | backend vẫn nhận `api.vitaminvui.vn` (Nginx ép) |
| Từ .20 kèm token + `X-Client-IP` | tới PHP nguyên vẹn (`tok=[T] cip=[1.2.3.4]`) |
| HEAD 200; POST 403; `/` 404 | đúng |
| Từ .21 (IP không allow): GET và POST | 403 |
| 250 request liên tiếp từ .20 vào 8081 | 250 x 200, Nginx không tự chặn (trần là của Laravel); access_log riêng ghi đủ 258 dòng |
| Host web, 300 request song song từ .21 vào `/` | 204 x 200, 96 x 429 (burst 200 + 1 + xả theo 10r/s) |
| Ngay sau đó 300 request `/_next/static/*` cùng IP | 300 x 200 (không bị limit) |
| `/` lần nữa khi đã cạn burst | 429 trở lại; IP .22 vẫn 200 (bucket riêng) |
| Host admin 300 request | 300 x 200 (không limit_req) |
| Gửi `X-Internal-Token: FORGED` + `X-Client-IP` tới api, admin-api, web (`/` và `/_next/static/`), admin, video `/videolab/cdn/` | cả 6 nơi backend/Next nhận `tok=[] cip=[]`; đối chứng gọi thẳng Next giả thì thấy `CTRL` (header bị xoá thật bởi Nginx) |

## Chạy test
`docker run --rm` (`vitaminvui-php:8.3`, mount `infra/production:ro`, `phpunit.local-g.xml`, DB `vitaminvui_testing_g` vừa tạo): `tests/Feature/T26` + `tests/Feature/T31` = **231 passed / 1414 assertions, 0 skip** (kể cả 25 test T31 trước đây skip, và `ProductionEnvExampleTest` với file env có thay đổi T37). Pint pass cho file test mới. Uptime load < 40, một tiến trình pest.

## Bug phát hiện
Không có.

## Rủi ro & đề xuất
- Burst 200 tại host web: một IP kéo tới 200 request tức thời mới bị 429, sau đó 10 req/s. Lớp học NAT ~40 máy có thể chạm 429 khi prefetch nhiều; chỉnh sau khi đo staging (đã ghi chú trong mẫu).
- Php-fpm là giả nên không kiểm được `CatalogThrottle` qua Nginx thật cùng lúc; phần Laravel được phủ bởi test (a)-(e), phần Nginx bởi kiểm trên. Nên chạy một lượt end to end (Next thật -> 8081 -> Laravel thật) trên staging, gồm 200 request/phút không IP không bị 429 và request 121 cùng `X-Client-IP` bị 429.
- R2/R3 của review (chuẩn hoá IPv6, đếm bucket IP khi trần tổng đầy) là NIT, không chặn.
- R4: file dùng chung với T37 (checklist, `.env` mẫu, api-contract) cần commit tách hoặc ghi chú.

## File thêm
`backend/tests/Feature/T31/NginxSsrInternalConfigTest.php` (6 test). Không sửa file ứng dụng, `infra/`, `frontend/`.
