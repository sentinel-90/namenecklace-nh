<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_sections', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 32)->unique();
            $table->string('name');
        });

        DB::table('catalog_sections')->insert([
            ['slug' => 'silver', 'name' => 'Серебро'],
            ['slug' => 'gold_plated', 'name' => 'Позолота'],
            ['slug' => 'gold', 'name' => 'Золото'],
            ['slug' => 'white_gold', 'name' => 'Белое золото'],
            ['slug' => 'red_gold', 'name' => 'Красное золото'],
        ]);

        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('catalog_sections')->restrictOnDelete();
            $table->string('type', 32);
            $table->string('name');
            $table->unsignedBigInteger('price_kopecks');
            $table->boolean('top')->nullable();
            $table->string('image_path');
            $table->unsignedInteger('image_width');
            $table->unsignedInteger('image_height');
            $table->string('preview_path');
            $table->unsignedInteger('preview_width');
            $table->unsignedInteger('preview_height');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
        Schema::dropIfExists('catalog_sections');
    }
};
