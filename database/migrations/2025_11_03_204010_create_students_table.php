<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->foreignId('college_id')->nullable()->constrained();
            $table->string('reg_no');
            $table->string('branch')->nullable();
            $table->string('cohort')->nullable();
            $table->json('meta')->nullable();
            $table->string('institution_name')->nullable();
            $table->string('university_name')->nullable();
            $table->string('gender', 20)->nullable();
            $table->date('dob')->nullable();
            $table->year('admission_year')->nullable();
            $table->unsignedTinyInteger('current_semester')->nullable();
            $table->string('training_status')->default('ready_for_baseline');
            $table->timestamps();

            $table->unique(['tenant_id','reg_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
