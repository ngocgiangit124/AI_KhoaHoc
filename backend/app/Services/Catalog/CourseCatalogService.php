<?php

namespace App\Services\Catalog;

use App\Models\Course;
use App\Support\Like;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Danh mục công khai (US-002, api-contract §2.1). Chỉ đọc — không đổi trạng
 * thái. Escape LIKE (S24) qua `App\Support\Like`.
 */
class CourseCatalogService
{
    /**
     * @param  array{grade?: int|null, subject_ids?: list<int>|null, q?: string|null, sort?: string|null}  $filters
     * @return LengthAwarePaginator<int, Course>
     */
    public function search(array $filters): LengthAwarePaginator
    {
        $query = Course::query()
            ->published()
            ->with(['teachers']);

        if (! empty($filters['grade'])) {
            $query->where('grade_level', $filters['grade']);
        }

        if (! empty($filters['subject_ids'])) {
            $subjectIds = $filters['subject_ids'];
            $query->whereHas('subjects', function ($q) use ($subjectIds): void {
                $q->whereIn('subjects.id', $subjectIds);
            });
        }

        if (! empty($filters['q'])) {
            $normalized = Str::lower(Str::ascii($filters['q']));
            $query->where('search_text', 'like', Like::contains($normalized));
        }

        $this->applySort($query, $filters['sort'] ?? 'newest');

        // BR4 (US-002) — 25 khóa/trang.
        return $query->paginate(25)->withQueryString();
    }

    /**
     * @param  Builder<Course>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'popular' => $query->orderByDesc('enrollments_count')->orderByDesc('id'),
            // BR6 — "Nổi bật": ưu tiên manual_order (nhỏ hơn = trước), khóa
            // chưa set (NULL) xếp sau, tie-break created_at desc, id desc.
            'featured' => $query
                ->orderByRaw('manual_order IS NULL')
                ->orderBy('manual_order')
                ->orderByDesc('created_at')
                ->orderByDesc('id'),
            // 'newest' (mặc định) — published_at desc, tie-break id desc.
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };
    }
}
