<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Morning or evening study for students and for sections (evening students study in evening sections).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('study_shift', 10)->default('morning')->after('admission_channel')->index();
        });
        Schema::table('course_offerings', function (Blueprint $table) {
            $table->string('shift', 10)->default('morning')->after('section');
        });
        DB::table('students')->where('admission_channel', 'evening')->update(['study_shift' => 'evening']);
    }

    public function down(): void
    {
        Schema::table('students', fn (Blueprint $table) => $table->dropColumn('study_shift'));
        Schema::table('course_offerings', fn (Blueprint $table) => $table->dropColumn('shift'));
    }
};
