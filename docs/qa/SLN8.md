# QA: SLN8 (chi tiết khóa -> trang học, apps/web)
**Kết quả:** PASS

## Độ phủ
| Kịch bản | Test | Kết quả |
|---|---|---|
| Sở hữu: "Tiếp tục học" và bấm bài trong mục lục vào đúng trang học | e2e sln8-real.spec.ts | PASS |
| Sở hữu nhưng chưa học bài nào (resume null): về đúng bài đầu; /hoc/{id} chuyển đúng bài | e2e sln8-qa.spec.ts (ca 1) | PASS |
| 375px: không tràn ngang; CTA cao 52px (>=44); thanh dính đáy nằm trong viewport; hàng bài cao 48px | sln8-qa.spec.ts (ca 2) | PASS |
| Tab tới link bài có outline 2px; Enter vào đúng bài | sln8-qa.spec.ts (ca 2) | PASS |
| Khách mở thẳng /hoc/289/bai/259: về đăng nhập, API 401, không có video | sln8-qa.spec.ts | PASS |
| HS chưa ghi danh mở thẳng URL bài: API 403, không video/tiêu đề bài; trang chi tiết không có link /hoc | sln8-qa.spec.ts | PASS |
| Vitest components/catalog | 22/22 | PASS |

## Bug
Không có.

## Ghi chú và rủi ro
- Chưa kiểm: ghi danh hết hạn/thu hồi sau khi cache viewer-state (backend vẫn chặn ở API; chưa test).
- "Học thử" cho người chưa sở hữu vẫn chưa bấm được (đã ghi trong review, ngoài phạm vi).
- Log WebServer có "destination stream closed early" lúc teardown (vô hại).
- Thêm file test: frontend/apps/web/e2e/sln8-qa.spec.ts và playwright.sln8qa.config.ts (khớp cả sln8-real và sln8-qa; distDir .next-e2e-sln8qa, đã xóa sau khi chạy).

## Lệnh đã chạy
- seed-e2e-sln8.sh --reset (course=289 l1=259 l2=260) + tinker tạo sln8-qa-x@example.com; --clean sau cùng (xác nhận 0 user/khóa còn lại).
- docker run playwright:v1.63.0-noble: playwright test --config playwright.sln8qa.config.ts --workers=1 --trace=off -> 5/5 pass.
- pnpm.sh --filter web exec vitest run components/catalog -> 22/22.
- Không đụng seed-e2e-learn/quiz, khóa 285/287, fw4-*/fw5-*; không migrate; không sửa code ứng dụng. Load trung bình 3-7 (uptime) khi chạy.
