<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('discount_audits', function (Blueprint $table) {
            $table->unique('idempotency_key', 'discount_audits_idempotency_key_unique');
        });
    }

    public function down()
    {
        Schema::table('discount_audits', function (Blueprint $table) {
            $table->dropUnique('discount_audits_idempotency_key_unique');
        });
    }
};
