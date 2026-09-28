<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * M5 (review bảo mật T01/T02) — chặn mọi đường SỬA/XOÁ đi qua Eloquent Builder
 * (không qua `$model->update()`/`delete()` nên override 2 method đó trên model
 * không đủ, và không phải lúc nào cũng bắn sự kiện `saving`/`deleting` nên
 * override sự kiện trên model cũng không đủ một mình):
 * `update()`, `delete()`, `increment()`, `decrement()`, `incrementEach()`,
 * `decrementEach()`, `touch()`, `upsert()`, `forceDelete()`, `truncate()`.
 *
 * KHÔNG override `toBase()`: nhiều thao tác ĐỌC hợp lệ (`count()`, `exists()`,
 * `sum()`, `avg()`...) được Eloquent Builder tự chuyển qua `toBase()` nội bộ
 * (xem `$passthru` trong `Illuminate\Database\Eloquent\Builder`) — chặn ở đó
 * sẽ chặn luôn các truy vấn đếm/kiểm tra tồn tại hợp lệ. Mọi method SỬA/XOÁ ở
 * trên đều là method công khai riêng, không cần đi qua `toBase()` để chặn.
 *
 * KHÔNG chặn được `DB::table('audit_logs')` (bỏ qua Eloquent hoàn toàn) hay
 * `TRUNCATE`/thao tác trực tiếp trên DB. TODO(DBA, task sau — xem
 * docs/security/review-T01-T02.md mục M5): giới hạn quyền MySQL của user ứng
 * dụng trên bảng `audit_logs` chỉ còn INSERT/SELECT, hoặc trigger
 * `BEFORE UPDATE/DELETE ... SIGNAL SQLSTATE '45000'`.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class ImmutableAuditLogBuilder extends Builder
{
    private const MESSAGE = 'AuditLog là bất biến — không được phép %s qua query builder.';

    public function update(array $values): int
    {
        throw new LogicException(sprintf(self::MESSAGE, 'update()'));
    }

    public function delete(): mixed
    {
        throw new LogicException(sprintf(self::MESSAGE, 'delete()'));
    }

    public function forceDelete(): mixed
    {
        throw new LogicException(sprintf(self::MESSAGE, 'forceDelete()'));
    }

    public function increment($column, $amount = 1, array $extra = []): int
    {
        throw new LogicException(sprintf(self::MESSAGE, 'increment()'));
    }

    public function decrement($column, $amount = 1, array $extra = []): int
    {
        throw new LogicException(sprintf(self::MESSAGE, 'decrement()'));
    }

    public function incrementEach(array $columns, array $extra = []): int
    {
        throw new LogicException(sprintf(self::MESSAGE, 'incrementEach()'));
    }

    public function decrementEach(array $columns, array $extra = []): int
    {
        throw new LogicException(sprintf(self::MESSAGE, 'decrementEach()'));
    }

    /**
     * @param  array<int, string>|string|null  $column
     */
    public function touch($column = null): int|false
    {
        throw new LogicException(sprintf(self::MESSAGE, 'touch()'));
    }

    /**
     * @param  array<int, array<string, mixed>>|array<string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        throw new LogicException(sprintf(self::MESSAGE, 'upsert()'));
    }

    /**
     * Không phải method của lớp cha (Eloquent Builder proxy `truncate()` sang
     * `$this->query` qua `__call`, bỏ qua mọi override phía trên) — khai báo
     * tường minh ở đây để PHP ưu tiên gọi method này thay vì rơi vào `__call`.
     */
    public function truncate(): void
    {
        throw new LogicException(sprintf(self::MESSAGE, 'truncate()'));
    }
}
