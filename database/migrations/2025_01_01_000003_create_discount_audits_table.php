<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDiscountAuditsTable extends Migration
{
    public function up()
    {
        Schema::create('discount_audits', function (Blueprint $table) {
            $table->id();
            $table->uuid('idempotency_key')->nullable()->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('discount_id')->nullable()->index();
            $table->string('action')->nullable()->index();
            $table->json('applied')->nullable();
            $table->decimal('original_amount', 14, 4)->nullable();
            $table->decimal('final_amount', 14, 4)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['idempotency_key', 'user_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('discount_audits');
    }
}
