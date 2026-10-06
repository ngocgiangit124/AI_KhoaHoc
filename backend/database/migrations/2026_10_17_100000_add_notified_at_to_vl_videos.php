<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cụm 4 M1: worker-video không còn đẩy `SendVideoLabWebhookJob` vào queue `default` của app (Redis ACL cô lập
     * worker). App tự phát hiện video xong/lỗi bằng `videolab:notify` (scheduler, mỗi phút) và đánh dấu bằng cột này.
     */
    public function up(): void
    {
        Schema::table('vl_videos', function (Blueprint $table) {
            $table->dateTime('notified_at')->nullable()->after('finished_at');
            $table->index(['status', 'notified_at']);
        });

        // Video đã xong/lỗi trước khi có cột này: coi như đã báo, không bắn lại webhook hàng loạt.
        DB::table('vl_videos')->whereIn('status', [4, 5])->whereNull('notified_at')->update(['notified_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('vl_videos', function (Blueprint $table) {
            $table->dropIndex(['status', 'notified_at']);
            $table->dropColumn('notified_at');
        });
    }
};
