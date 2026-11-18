<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OfferingAdminController;
use App\Http\Controllers\OfferingController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StructureAdminController;
use App\Http\Controllers\StructureController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/students', [StudentController::class, 'index'])->name('students.index');
    Route::get('/students/create', [StudentController::class, 'create'])->name('students.create');
    Route::get('/students/duplicates', [StudentController::class, 'duplicates'])->name('students.duplicates');
    Route::post('/students', [StudentController::class, 'store'])->name('students.store');
    Route::get('/students/{student}', [StudentController::class, 'show'])->name('students.show');
    Route::post('/students/{student}/status', [StudentController::class, 'changeStatus'])->name('students.status');

    Route::get('/offerings', [OfferingController::class, 'index'])->name('offerings.index');
    Route::get('/offerings/create', [OfferingAdminController::class, 'create'])->name('offerings.create');
    Route::post('/offerings', [OfferingAdminController::class, 'store'])->name('offerings.store');
    Route::get('/offerings/teachers', [OfferingAdminController::class, 'teacherOptions'])->name('offerings.teachers');
    Route::put('/offerings/{offering}', [OfferingAdminController::class, 'update'])->name('offerings.update');
    Route::put('/offerings/{offering}/components', [OfferingAdminController::class, 'components'])->name('offerings.components');
    Route::get('/offerings/{offering}/students/search', [OfferingAdminController::class, 'searchStudents'])->name('offerings.students.search');
    Route::post('/offerings/{offering}/students', [OfferingAdminController::class, 'enroll'])->name('offerings.students.store');
    Route::post('/offerings/{offering}/enrollments/{enrollment}/withdraw', [OfferingAdminController::class, 'withdraw'])->name('offerings.withdraw');
    Route::get('/offerings/{offering}', [OfferingController::class, 'show'])->name('offerings.show');
    Route::post('/offerings/{offering}/marks', [OfferingController::class, 'saveMarks'])->name('offerings.marks');
    Route::post('/offerings/{offering}/compute', [OfferingController::class, 'compute'])->name('offerings.compute');
    Route::post('/offerings/{offering}/submit', [OfferingController::class, 'submit'])->name('offerings.submit');
    Route::post('/offerings/{offering}/decide', [OfferingController::class, 'decide'])->name('offerings.decide');
    Route::post('/offerings/{offering}/enroll-cohort', [OfferingController::class, 'enrollCohort'])->name('offerings.enroll');
    Route::post('/offerings/{offering}/results/{result}/correct', [OfferingController::class, 'correct'])->name('offerings.correct');

    Route::get('/structure', [StructureController::class, 'index'])->name('structure.index');
    Route::get('/curricula/{curriculum}', [StructureController::class, 'curriculum'])->name('curricula.show');

    Route::middleware('can:manage-structure')->controller(StructureAdminController::class)->group(function () {
        Route::post('/colleges', 'saveCollege')->name('colleges.store');
        Route::put('/colleges/{college}', 'saveCollege')->name('colleges.update');
        Route::post('/departments', 'saveDepartment')->name('departments.store');
        Route::put('/departments/{department}', 'saveDepartment')->name('departments.update');
        Route::post('/programs', 'saveProgram')->name('programs.store');
        Route::put('/programs/{program}', 'saveProgram')->name('programs.update');
        Route::get('/courses', 'courses')->name('courses.index');
        Route::post('/courses', 'saveCourse')->name('courses.store');
        Route::put('/courses/{course}', 'saveCourse')->name('courses.update');
        Route::post('/curricula/{curriculum}/versions', 'newCurriculumVersion')->name('curricula.version');
        Route::post('/curricula/{curriculum}/courses', 'addCurriculumCourse')->name('curricula.courses.store');
        Route::delete('/curricula/{curriculum}/courses/{item}', 'removeCurriculumCourse')->name('curricula.courses.destroy');
        Route::post('/curricula/{curriculum}/approve', 'approveCurriculum')->name('curricula.approve');
        Route::post('/years', 'saveYear')->name('years.store');
        Route::post('/years/{year}/current', 'makeYearCurrent')->name('years.current');
        Route::put('/terms/{term}', 'saveTerm')->name('terms.update');
    });

    Route::get('/policies', [PolicyController::class, 'index'])->name('policies.index');
    Route::post('/policies/{policy}/versions', [PolicyController::class, 'store'])->name('policies.version');
    Route::post('/policies/{policy}/approve', [PolicyController::class, 'approve'])->name('policies.approve');
    Route::delete('/policies/{policy}', [PolicyController::class, 'destroy'])->name('policies.destroy');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');

    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

require __DIR__.'/auth.php';
