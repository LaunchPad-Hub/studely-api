
<?php
use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('assessment_id')->constrained();
            $table->foreignId('student_id')->constrained();
            $table->timestamp('started_at');
            $table->timestamp('submitted_at')->nullable();
            $table->integer('duration_sec')->default(0);
            $table->decimal('score',6,2)->default(1);
            $table->decimal('total_marks',6,2)->default(1);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['assessment_id','student_id']);
        });

        Schema::create('responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained();
            $table->foreignId('question_id')->constrained();
            $table->foreignId('option_id')->nullable()->constrained('options');
            $table->text('text_answer')->nullable();
            $table->timestamps();
            $table->unique(['attempt_id','question_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('responses');
        Schema::dropIfExists('attempts');
    }
};
