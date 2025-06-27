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
    Schema::create('audit_questions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('audit_section_id')->nullable()->constrained()->onDelete('cascade');
        $table->string('report_category')->nullable();
        $table->text('question')->nullable();
        $table->enum('answer', ['Yes', 'No', 'N/A'])->nullable();
        $table->string('points_current')->nullable();
        $table->string('points_total')->nullable();
        $table->string('percent', 5, 2)->nullable();
        $table->text('comments')->nullable();
        $table->integer('order')->nullable();
        $table->timestamps();
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_questions');
    }
};
