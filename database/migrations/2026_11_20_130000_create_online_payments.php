<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One attempt to pay online. It becomes a receipt (payments row) only after the gateway confirms it.
        Schema::create('online_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('student_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('gateway', 16);
            $table->unsignedBigInteger('amount');
            $table->string('status', 12)->default('pending');   // pending | paid | failed | expired | cancelled | review
            $table->string('external_id')->nullable();
            $table->text('redirect_url')->nullable();
            $table->json('display')->nullable();                  // QR code, readable code, app link (FIB)
            $table->string('message')->nullable();                // why it failed or needs review
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['gateway', 'external_id']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_payments');
    }
};
