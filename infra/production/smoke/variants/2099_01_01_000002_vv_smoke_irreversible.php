<?php
// VV-IRREVERSIBLE: migration giả của smoke để kiểm deploy.sh dừng khi thiếu --ack-irreversible

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
