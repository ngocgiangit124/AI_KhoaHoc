# Bộ agent Laravel cho Claude Code

Mười subagent mô phỏng một đội phát triển, trao đổi với nhau qua file trong thư mục `docs/`:

| Nhóm | Agent | Model | Quyền |
|---|---|---|---|
| Điều phối | `laravel-orchestrator` | opus | Gọi agent khác, ghi `docs/board.md` |
| Phân tích | `laravel-ba` | sonnet | Đọc + ghi docs |
| | `laravel-designer` | sonnet | Đọc + ghi docs, mockup |
| | `laravel-architect` | opus | Đọc + ghi docs, có memory |
| Xây dựng | `laravel-dev` | sonnet | Toàn quyền code, Bash |
| Kiểm soát | `laravel-reviewer` | sonnet | Đọc code, Bash kiểm tra, chỉ ghi báo cáo, có memory |
| | `laravel-qa` | sonnet | Chỉ ghi `tests/`, `database/factories/` |
| | `laravel-security` | opus | Đọc code, Bash audit, chỉ ghi báo cáo |
| | `laravel-dba` | sonnet | SQL Server; ghi migration + docs, có memory |
| Phát hành | `laravel-release` | sonnet | Đọc git, soạn checklist, không deploy |

## Cài đặt
Copy thư mục `.claude/agents/` vào gốc dự án Laravel:

```
your-laravel-app/
├── CLAUDE.md                 # bối cảnh dự án (nên có)
├── .claude/agents/           # 10 file agent
└── docs/                     # agent tự tạo
    ├── board.md              # orchestrator theo dõi tiến độ
    ├── stories/              # BA
    ├── design/ (+ mockups/)  # Designer
    ├── tech/  adr/           # Architect
    ├── review/               # Reviewer
    ├── qa/                   # QA
    ├── security/             # Security
    ├── db/                   # DBA
    └── releases/             # Release
```

Muốn dùng cho mọi dự án thì copy vào `~/.claude/agents/`. Kiểm tra bằng lệnh `/agents` trong Claude Code, hoặc `claude plugin validate .claude/agents/`.

## Quy trình

```
Yêu cầu PO
   │
   ▼
laravel-ba ──► [PO duyệt story]
   │
   ├──► laravel-designer ─┐   (song song)
   └──► laravel-architect ┴──► laravel-dba (nếu dữ liệu lớn) ──► [PO duyệt thiết kế]
                                   │
                                   ▼
                              laravel-dev ◄──────────────┐
                                   │                     │ REQUEST CHANGES / FAIL
                                   ▼                     │
                            laravel-reviewer ────────────┤
                                   │ APPROVE             │
                                   ▼                     │
                              laravel-qa ────────────────┤
                                   │ PASS                │
                                   ▼                     │
                     laravel-security (khi cần) ─────────┘
                                   │
                                   ▼
                          Done ──► laravel-release
```

| Bước | Agent | Đầu vào | Đầu ra |
|---|---|---|---|
| 1 | `laravel-ba` | Yêu cầu thô từ PO | `docs/stories/US-xxx-*.md` |
| 2a | `laravel-designer` | Story | `docs/design/US-xxx.md` + mockup HTML |
| 2b | `laravel-architect` | Story (+ design) | `docs/tech/US-xxx.md`, `docs/adr/ADR-xxx.md` |
| 2c | `laravel-dba` | Thiết kế dữ liệu | `docs/db/US-xxx.md` |
| 3 | `laravel-dev` | Story + design + tech | Code, migration, test pass |
| 4 | `laravel-reviewer` | Diff + story | `docs/review/US-xxx.md` (APPROVE / REQUEST CHANGES) |
| 5 | `laravel-qa` | Story + code + review | Test tự động, `docs/qa/US-xxx.md` (PASS / FAIL) |
| 6 | `laravel-security` | Diff + story | `docs/security/US-xxx.md` |
| 7 | `laravel-release` | Git log + docs | `docs/releases/vX.Y.Z.md` |

**Khi nào gọi Security:** story đụng đăng nhập/phân quyền, upload/tải file, dữ liệu cá nhân khách hàng (họ tên, SĐT, địa chỉ người gửi/nhận), API công khai/đối tác, tiền.
**Khi nào gọi DBA:** migration trên bảng lớn, báo cáo/xuất Excel dữ liệu lớn, truy vấn chậm, deadlock, lấy dữ liệu từ kho dữ liệu.

## Cách gọi

### Cách 1 — Để orchestrator chạy cả quy trình (khuyến nghị)
```bash
claude --agent laravel-orchestrator
```
Rồi nhập:
```
Làm tính năng: cho phép bưu cục xuất báo cáo đơn hàng theo ngày ra Excel.
```
Orchestrator sẽ gọi lần lượt các agent, dừng ở 2 cổng duyệt của PO (sau story, sau thiết kế) và ghi tiến độ vào `docs/board.md`.

Có thể đặt mặc định trong `.claude/settings.json`: `{ "agent": "laravel-orchestrator" }`.

### Cách 2 — Gọi từng agent
```
@agent-laravel-ba phân tích yêu cầu: cho phép bưu cục xuất báo cáo đơn hàng theo ngày ra Excel
@agent-laravel-architect thiết kế kỹ thuật cho US-012
@agent-laravel-dev hiện thực US-012 theo docs/tech/US-012.md
@agent-laravel-reviewer review thay đổi của US-012
@agent-laravel-qa kiểm thử US-012
@agent-laravel-security audit toàn dự án trước release
@agent-laravel-dba xem vì sao báo cáo sản lượng theo tháng chạy 40 giây
@agent-laravel-release chuẩn bị release v1.4.0
```
`@agent-...` bắt buộc chạy đúng agent đó; viết "Dùng laravel-xxx ..." thì Claude tự quyết có giao hay không.

## Nên có CLAUDE.md
Tạo `CLAUDE.md` ở gốc dự án để mọi agent hiểu bối cảnh, ví dụ:

```markdown
# Dự án: <tên>
- Laravel 11, PHP 8.3, SQL Server 2022 (driver sqlsrv), collation <...>
- Connection: sqlsrv (OLTP), dw (kho dữ liệu, chỉ đọc)
- Frontend: Blade + Tailwind (hoặc Livewire / Inertia)
- Test: Pest · Code style: Pint · Phân tích tĩnh: Larastan (nếu có)
- Hạ tầng: <IIS/Nginx, Windows/Linux, số server, cách chạy queue>
- Thuật ngữ: bưu cục = post office, vận đơn = waybill ...
- Vai trò người dùng: admin, quản lý bưu cục, nhân viên
- Quy ước: mã story US-xxx trong tên nhánh và commit message
```

## Tuỳ chỉnh
- **model**: `opus` cho việc cần suy luận sâu (architect, security, orchestrator), `sonnet` cho phần lớn việc, `haiku` để tiết kiệm (ví dụ release khi dự án nhỏ). Có thể thêm `effort: high` cho agent cần nghĩ kỹ.
- **tools / disallowedTools**: giới hạn quyền. BA, Designer, Architect không chạy Bash; Reviewer, Security, Release không có Edit và chỉ được dặn ghi báo cáo; QA không sửa `app/`.
- **Agent(...)** trong `tools` của orchestrator: chỉ cho gọi đúng 9 agent của đội. Danh sách này chỉ có hiệu lực khi orchestrator chạy làm phiên chính (`claude --agent`).
- **memory: project**: Architect, Reviewer, DBA tự ghi nhớ quy ước, lỗi hay lặp lại, bảng lớn/index của dự án qua các phiên. Thêm cho agent khác nếu muốn.
- **color**: màu hiển thị của từng agent trong giao diện.
- Subagent mặc định được lồng tối đa 3 tầng — đủ cho orchestrator (tầng 1) gọi các agent khác (tầng 2).

## Lịch sử thay đổi
- **v2.1** (2026-09): Architect, Reviewer, DBA chỉ chạy khi được gọi đích danh hoặc do orchestrator giao (bỏ PROACTIVELY); BA dừng sau khi viết story; orchestrator chỉ làm đúng phạm vi PO yêu cầu (ví dụ "chỉ bước BA" → dừng sau BA).
- **v2** (2026-09): thêm `laravel-orchestrator`, `laravel-architect`, `laravel-reviewer`, `laravel-security`, `laravel-dba`, `laravel-release`; Dev đọc thêm `docs/tech/`, QA đọc thêm `docs/review/`, `docs/security/`; sửa lỗi YAML ở mô tả của `laravel-designer` (dấu `:` khiến frontmatter không hợp lệ).
- **v1**: BA → Designer → Dev → QA.
