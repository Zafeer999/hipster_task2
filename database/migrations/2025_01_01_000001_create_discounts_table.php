<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDiscountsTable extends Migration
{
    public function up()
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable()->index();
            $table->enum('type', ['percentage', 'fixed'])->default('fixed');
            $table->decimal('value', 12, 4)->default(0);
            $table->integer('priority')->default(0)->index();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('max_uses_per_user')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->decimal('percentage', 8, 4)->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->decimal('fixed_amount', 12, 4)->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('discounts');
    }
}
