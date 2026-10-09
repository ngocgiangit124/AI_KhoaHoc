<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Migration giả của smoke: thêm bảng vô hại, có down().
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vv_smoke_fake', function (Blueprint $table) {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vv_smoke_fake');
    }
};
