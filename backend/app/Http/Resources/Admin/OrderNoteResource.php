<?php

namespace App\Http\Resources\Admin;

use App\Models\OrderNote;
use App\Support\VnTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ghi chú nội bộ trên đơn (api-contract §2.5.1). Chỉ quản trị thấy; `author` nạp sẵn (`id`, `name`).
 *
 * @property OrderNote $resource
 */
class OrderNoteResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $note = $this->resource;

        return [
            'id' => $note->getKey(),
            'body' => $note->body,
            'author' => ['id' => (int) $note->author_id, 'name' => (string) $note->author?->name],
            'created_at' => VnTime::iso($note->created_at),
        ];
    }
}
