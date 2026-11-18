<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OfferingController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StructureController;
use App\Http\Controllers\StudentController;
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
    Route::get('/offerings/{offering}', [OfferingController::class, 'show'])->name('offerings.show');
    Route::post('/offerings/{offering}/marks', [OfferingController::class, 'saveMarks'])->name('offerings.marks');
    Route::post('/offerings/{offering}/compute', [OfferingController::class, 'compute'])->name('offerings.compute');
    Route::post('/offerings/{offering}/submit', [OfferingController::class, 'submit'])->name('offerings.submit');
    Route::post('/offerings/{offering}/decide', [OfferingController::class, 'decide'])->name('offerings.decide');
    Route::post('/offerings/{offering}/enroll-cohort', [OfferingController::class, 'enrollCohort'])->name('offerings.enroll');
    Route::post('/offerings/{offering}/results/{result}/correct', [OfferingController::class, 'correct'])->name('offerings.correct');

    Route::get('/structure', [StructureController::class, 'index'])->name('structure.index');
    Route::get('/curricula/{curriculum}', [StructureController::class, 'curriculum'])->name('curricula.show');

    Route::get('/policies', [PolicyController::class, 'index'])->name('policies.index');
    Route::post('/policies/{policy}/versions', [PolicyController::class, 'store'])->name('policies.version');
    Route::post('/policies/{policy}/approve', [PolicyController::class, 'approve'])->name('policies.approve');
    Route::delete('/policies/{policy}', [PolicyController::class, 'destroy'])->name('policies.destroy');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');

    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

require __DIR__.'/auth.php';
