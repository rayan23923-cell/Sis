<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A questionnaire. The quality unit writes the official one; until then a sample, marked unofficial.
        Schema::create('evaluation_forms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('questions');                             // [{text, kind: scale|text}]
            $table->boolean('is_official')->default(false);
            $table->string('source_reference')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // A period in which students of a term (optionally one college) evaluate their courses.
        Schema::create('evaluation_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_form_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->foreignId('college_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->date('opens_on');
            $table->date('closes_on');
            $table->unsignedSmallInteger('min_responses')->default(5);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Who has answered, to stop a second answer. Holds no answers and no time, so it cannot be matched to them.
        Schema::create('evaluation_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->unique(['evaluation_round_id', 'course_offering_id', 'student_id'], 'eval_sub_unique');
        });

        // The answers, anonymous: a random key, no student, and only the date.
        Schema::create('evaluation_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('evaluation_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('scores');                                // question index => 1..5
            $table->text('comment')->nullable();
            $table->date('answered_on');
            $table->index(['evaluation_round_id', 'course_offering_id']);
        });

        DB::table('evaluation_forms')->insert([
            'name' => 'استمارة تقييم التدريسي والمادة (نموذج مؤقت)',
            'questions' => json_encode([
                ['text' => 'وضّح التدريسي أهداف المادة ومفرداتها في بداية الفصل.', 'kind' => 'scale'],
                ['text' => 'يلتزم التدريسي بمواعيد المحاضرات ومدتها.', 'kind' => 'scale'],
                ['text' => 'يشرح التدريسي المادة بوضوح وتسلسل منطقي.', 'kind' => 'scale'],
                ['text' => 'يشجع التدريسي الطلبة على المشاركة وطرح الأسئلة.', 'kind' => 'scale'],
                ['text' => 'يتعامل التدريسي مع الطلبة باحترام وعدالة.', 'kind' => 'scale'],
                ['text' => 'الامتحانات والتقييمات مرتبطة بما دُرّس.', 'kind' => 'scale'],
                ['text' => 'يعيد التدريسي النتائج ويوضح الأخطاء في وقت مناسب.', 'kind' => 'scale'],
                ['text' => 'المصادر والمراجع المقترحة مفيدة ومتاحة.', 'kind' => 'scale'],
                ['text' => 'تقييمي العام للمادة.', 'kind' => 'scale'],
                ['text' => 'ملاحظات أو مقترحات لتحسين المادة.', 'kind' => 'text'],
            ], JSON_UNESCAPED_UNICODE),
            'is_official' => false,
            'source_reference' => 'نموذج مؤقت أعدّه النظام؛ يُستبدل باستمارة شعبة ضمان الجودة المعتمدة.',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_answers');
        Schema::dropIfExists('evaluation_submissions');
        Schema::dropIfExists('evaluation_rounds');
        Schema::dropIfExists('evaluation_forms');
    }
};
