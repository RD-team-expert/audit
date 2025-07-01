<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::create('audit_sections', function (Blueprint $table) {
        $table->id();
        $table->foreignId('audit_report_id')->constrained()->onDelete('cascade');
        $table->string('section_type'); // e.g., 'audit_category' or 'report_category'
        $table->string('category');
        $table->integer('points')->nullable()->default(0);
        $table->integer('total_points')->nullable()->default(0);
        $table->float('score')->nullable()->default(0);
        $table->integer('order')->nullable()->default(0);
        $table->timestamps();
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_sections');
    }
};
