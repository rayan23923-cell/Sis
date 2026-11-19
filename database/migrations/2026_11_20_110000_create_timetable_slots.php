<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The weekly timetable: when and where each section meets. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day');            // 0 = Saturday … 6 = Friday
            $table->time('starts_at');
            $table->time('ends_at');
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10)->default('lecture'); // lecture | lab
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['day', 'starts_at']);
            $table->index('room_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_slots');
    }
};
