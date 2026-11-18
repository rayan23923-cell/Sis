<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tuition fees. Amounts are in Iraqi dinars (whole numbers) and are entered by the university:
 * the system ships with no fee schedule, so nobody is invoiced until finance defines one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->foreignId('college_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('admission_channel', 20)->nullable();   // null = any channel
            $table->string('study_shift', 10)->nullable();         // null = any shift
            $table->unsignedTinyInteger('stage')->nullable();      // null = every stage
            $table->unsignedBigInteger('amount');
            $table->json('installments');                          // [{percent, due_on}]
            $table->boolean('is_active')->default(true);
            $table->string('source_reference')->nullable();        // the decision that set the amount
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['academic_year_id', 'is_active']);
        });

        Schema::create('student_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('fee_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('admission_channel', 20)->nullable();
            $table->string('study_shift', 10)->nullable();
            $table->unsignedTinyInteger('stage')->nullable();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('discount')->default(0);
            $table->string('discount_reason')->nullable();
            $table->string('status', 12)->default('open');         // open | paid | cancelled
            $table->string('cancel_reason')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['academic_year_id', 'status']);
            $table->index(['student_id', 'academic_year_id']);
        });

        Schema::create('invoice_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('number');
            $table->unsignedBigInteger('amount');
            $table->date('due_on');
            $table->timestamps();
            $table->unique(['student_invoice_id', 'number']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 20)->unique();
            $table->foreignId('student_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('paid_on');
            $table->string('method', 12);                          // cash | bank | electronic
            $table->string('reference')->nullable();               // bank slip / transaction number
            $table->string('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->index(['paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_installments');
        Schema::dropIfExists('student_invoices');
        Schema::dropIfExists('fee_schedules');
    }
};
