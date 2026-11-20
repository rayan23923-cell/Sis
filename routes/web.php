<?php

use App\Http\Controllers\Api\AppController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentRequestController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MasterSheetController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OfferingAdminController;
use App\Http\Controllers\OfferingController;
use App\Http\Controllers\Offline\ActivationController;
use App\Http\Controllers\OnlinePaymentController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProgressionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\StructureAdminController;
use App\Http\Controllers\StructureController;
use App\Http\Controllers\StudentAccountController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TimetableController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VerifyController;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

Route::redirect('/', '/dashboard');

// Public verification of printed documents (QR code).
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/verify', [VerifyController::class, 'form'])->name('verify.form');
    Route::get('/verify/{code}', [VerifyController::class, 'show'])->name('verify');
});

Route::post('/locale', function (Request $request) {
    $locale = $request->validate(['locale' => ['required', Rule::in(SetLocale::LOCALES)]])['locale'];
    $request->session()->put('locale', $locale);
    $request->user()?->update(['locale' => $locale]);

    return back();
})->name('locale');

Route::middleware(['auth', 'active', 'password.changed', 'two-factor', 'staff'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/students', [StudentController::class, 'index'])->name('students.index');
    Route::get('/students/create', [StudentController::class, 'create'])->name('students.create');
    Route::get('/students/duplicates', [StudentController::class, 'duplicates'])->name('students.duplicates');
    Route::get('/students/import', [ImportController::class, 'studentsForm'])->name('students.import');
    Route::get('/students/import/template', [ImportController::class, 'studentsTemplate'])->name('students.import.template');
    Route::post('/students/import/preview', [ImportController::class, 'studentsPreview'])->name('students.import.preview');
    Route::post('/students/import/commit', [ImportController::class, 'studentsCommit'])->name('students.import.commit');
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
    Route::get('/offerings/{offering}/attendance', [AttendanceController::class, 'show'])->name('offerings.attendance');
    Route::post('/offerings/{offering}/attendance', [AttendanceController::class, 'store'])->name('offerings.attendance.store');
    Route::put('/offerings/{offering}/attendance/{session}', [AttendanceController::class, 'update'])->name('offerings.attendance.update');
    Route::delete('/offerings/{offering}/attendance/{session}', [AttendanceController::class, 'destroy'])->name('offerings.attendance.destroy');
    Route::get('/offerings/{offering}', [OfferingController::class, 'show'])->name('offerings.show');
    Route::post('/offerings/{offering}/marks', [OfferingController::class, 'saveMarks'])->name('offerings.marks');
    Route::post('/offerings/{offering}/compute', [OfferingController::class, 'compute'])->name('offerings.compute');
    Route::post('/offerings/{offering}/submit', [OfferingController::class, 'submit'])->name('offerings.submit');
    Route::post('/offerings/{offering}/decide', [OfferingController::class, 'decide'])->name('offerings.decide');
    Route::post('/offerings/{offering}/enroll-cohort', [OfferingController::class, 'enrollCohort'])->name('offerings.enroll');
    Route::post('/offerings/{offering}/results/{result}/correct', [OfferingController::class, 'correct'])->name('offerings.correct');

    Route::get('/progression', [ProgressionController::class, 'index'])->name('progression.index');
    Route::get('/offerings/{offering}/marks.xlsx', [ImportController::class, 'marksTemplate'])->name('offerings.marks.template');
    Route::post('/offerings/{offering}/marks/import', [ImportController::class, 'marksImport'])->name('offerings.marks.import');
    Route::get('/progression/pdf', [DocumentController::class, 'yearResults'])->name('progression.pdf');
    Route::get('/offerings/{offering}/sheet.pdf', [DocumentController::class, 'gradeSheet'])->name('offerings.sheet');
    Route::post('/progression/save', [ProgressionController::class, 'save'])->name('progression.save');
    Route::post('/progression/apply', [ProgressionController::class, 'apply'])->name('progression.apply');

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
    Route::get('/master-sheet', [MasterSheetController::class, 'show'])->name('master-sheet.show');
    Route::get('/master-sheet/pdf', [MasterSheetController::class, 'pdf'])->name('master-sheet.pdf');
    Route::get('/master-sheet/excel', [MasterSheetController::class, 'excel'])->name('master-sheet.excel');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');

    Route::controller(FinanceController::class)->prefix('finance')->name('finance.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/export', 'export')->name('export');
        Route::post('/invoice-all', 'invoiceAll')->name('invoice-all');
        Route::get('/schedules', 'schedules')->name('schedules');
        Route::post('/schedules', 'storeSchedule')->name('schedules.store');
        Route::put('/schedules/{schedule}', 'updateSchedule')->name('schedules.update');
        Route::delete('/schedules/{schedule}', 'destroySchedule')->name('schedules.destroy');
        Route::get('/students/{student}', 'student')->name('student');
        Route::post('/students/{student}/invoice', 'invoiceStudent')->name('student.invoice');
        Route::post('/invoices/{invoice}/pay', 'pay')->name('pay');
        Route::post('/invoices/{invoice}/discount', 'discount')->name('discount');
        Route::post('/invoices/{invoice}/cancel', 'cancel')->name('cancel');
        Route::post('/payments/{payment}/void', 'void')->name('void');
    });

    Route::post('/students/{student}/account', [StudentAccountController::class, 'issue'])->name('students.account');
    Route::post('/documents/{document}/revoke', [VerifyController::class, 'revoke'])->name('documents.revoke');
    Route::post('/student-accounts', [StudentAccountController::class, 'bulk'])->name('students.accounts.bulk');

    Route::controller(EvaluationController::class)->prefix('evaluations')->name('evaluations.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/forms', 'storeForm')->name('forms.store');
        Route::put('/forms/{form}', 'updateForm')->name('forms.update');
        Route::post('/rounds', 'storeRound')->name('rounds.store');
        Route::put('/rounds/{round}', 'updateRound')->name('rounds.update');
    });

    Route::controller(DocumentRequestController::class)->prefix('requests')->name('requests.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/{documentRequest}/issue', 'issue')->name('issue');
        Route::post('/{documentRequest}/reject', 'reject')->name('reject');
        Route::post('/{documentRequest}/deliver', 'deliver')->name('deliver');
    });

    Route::controller(TimetableController::class)->group(function () {
        Route::get('/timetable', 'index')->name('timetable.index');
        Route::get('/timetable/pdf', 'pdf')->name('timetable.pdf');
        Route::post('/timetable/slots', 'store')->name('timetable.store');
        Route::put('/timetable/slots/{slot}', 'update')->name('timetable.update');
        Route::delete('/timetable/slots/{slot}', 'destroy')->name('timetable.destroy');
        Route::get('/rooms', 'rooms')->name('rooms.index');
        Route::post('/rooms', 'storeRoom')->name('rooms.store');
        Route::put('/rooms/{room}', 'updateRoom')->name('rooms.update');
    });

    Route::controller(\App\Http\Controllers\ConnectorController::class)->prefix('connectors')->name('connectors.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/{key}/connect', 'connect')->middleware('throttle:10,1')->name('connect');
        Route::post('/{key}/disconnect', 'disconnect')->name('disconnect');
        Route::put('/{key}/tools', 'tools')->name('tools');
        Route::post('/{key}/rotate', 'rotate')->middleware('throttle:10,1')->name('rotate');
        Route::post('/{key}/test', 'test')->middleware('throttle:10,1')->name('test');
        Route::post('/{key}/sync', 'sync')->middleware('throttle:6,1')->name('sync');
    });
    Route::post('/offerings/{offering}/incoming/{batch}/apply', [OfferingController::class, 'applyIncoming'])->name('offerings.incoming.apply');
    Route::post('/offerings/{offering}/incoming/{batch}/reject', [OfferingController::class, 'rejectIncoming'])->name('offerings.incoming.reject');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

// Shared by staff and students (each checks its own access).
Route::middleware(['auth', 'active', 'password.changed', 'two-factor'])->group(function () {
    Route::get('/portal', [PortalController::class, 'home'])->name('portal.home');
    Route::get('/students/{student}/transcript.pdf', [DocumentController::class, 'transcript'])->name('students.transcript');
    Route::get('/students/{student}/graduation.pdf', [DocumentController::class, 'graduation'])->name('students.graduation');
    Route::get('/students/{student}/enrollment.pdf', [DocumentController::class, 'enrollment'])->name('students.enrollment');
    Route::get('/finance/receipts/{payment}.pdf', [FinanceController::class, 'receipt'])->name('finance.receipt');
    Route::get('/my-timetable.pdf', [TimetableController::class, 'mine'])->name('timetable.mine');
    Route::post('/portal/requests', [DocumentRequestController::class, 'store'])->middleware('throttle:10,1')->name('portal.requests.store');
    Route::post('/portal/requests/{documentRequest}/cancel', [DocumentRequestController::class, 'cancel'])->name('portal.requests.cancel');
    Route::get('/requests/{documentRequest}/file', [DocumentRequestController::class, 'file'])->name('requests.file');
    Route::post('/portal/fees/{invoice}/pay', [OnlinePaymentController::class, 'start'])->middleware('throttle:10,1')->name('payments.start');
    Route::get('/payments/{onlinePayment}', [OnlinePaymentController::class, 'show'])->name('payments.show');
    Route::post('/payments/{onlinePayment}/check', [OnlinePaymentController::class, 'check'])->middleware('throttle:30,1')->name('payments.check');
    Route::post('/payments/{onlinePayment}/cancel', [OnlinePaymentController::class, 'cancel'])->name('payments.cancel');
    Route::get('/payments/{onlinePayment}/sandbox', [OnlinePaymentController::class, 'sandbox'])->name('payments.sandbox');
    Route::post('/payments/{onlinePayment}/sandbox', [OnlinePaymentController::class, 'sandboxDecide'])->name('payments.sandbox.decide');
    Route::post('/payments/{onlinePayment}/reviewed', [OnlinePaymentController::class, 'reviewed'])->name('payments.reviewed');
    Route::post('/portal/evaluations', [EvaluationController::class, 'submit'])->middleware('throttle:30,1')->name('portal.evaluations.submit');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/{id}', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('/notifications/read', [NotificationController::class, 'readAll'])->name('notifications.read');
});

// The account's own security page (reachable before two-step sign-in is set up, so it can be set up).
Route::middleware(['auth', 'active', 'password.changed'])->controller(SecurityController::class)->prefix('security')->name('security.')->group(function () {
    Route::get('/', 'show')->name('show');
    Route::post('/two-factor', 'begin')->name('two-factor.begin');
    Route::post('/two-factor/confirm', 'confirm')->middleware('throttle:10,1')->name('two-factor.confirm');
    Route::delete('/two-factor', 'disable')->middleware('throttle:10,1')->name('two-factor.disable');
    Route::post('/recovery-codes', 'recoveryCodes')->middleware('throttle:10,1')->name('recovery-codes');
    Route::delete('/sessions', 'endSessions')->middleware('throttle:10,1')->name('sessions.destroy');
});
Route::middleware(['auth', 'active', 'password.changed', 'two-factor', 'staff'])->group(function () {
    Route::get('/security/logins', [SecurityController::class, 'logins'])->name('logins.index');
    Route::delete('/users/{user}/two-factor', [SecurityController::class, 'reset'])->name('users.two-factor.reset');
});

Route::middleware(['auth', 'active'])->get('/password/first', [PortalController::class, 'password'])->name('password.first');

// The apps open the full site through a one-time link (see Api\AppController::webLink).
Route::get('/app/enter/{token}', [AppController::class, 'enter'])->middleware('throttle:30,1')->where('token', '[A-Za-z0-9]{48}');

// The apps and the offline server for download (public, no login); files are uploaded by their workflows.
Route::get('/download', function () {
    $file = public_path('downloads/releases.json');
    $releases = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

    return view('download', ['releases' => is_array($releases) ? $releases : []]);
})->name('download');

// The offline server's activation page (same licensing portal as FileNest); see EnsureOfflineActivated.
Route::get('/activate', [ActivationController::class, 'show']);
Route::post('/activate', [ActivationController::class, 'store'])->middleware('throttle:10,1');

// The university's name for the offline server's Wi-Fi pages (/setup).
Route::get('/brand.json', fn () => response()->json(['name' => config('sis.university_name'), 'logo' => null]));

require __DIR__.'/auth.php';

// Online payment gateways: the payer's return and the gateway's server call. Neither is trusted; each only
// prompts the system to ask the gateway for the payment's state.
Route::match(['get', 'post'], '/payments/{onlinePayment}/return', [OnlinePaymentController::class, 'back'])->middleware('throttle:30,1')->name('payments.return');
Route::post('/payment-notify/{gateway}', [OnlinePaymentController::class, 'notify'])
    ->whereIn('gateway', ['qicard', 'zaincash', 'fib', 'sandbox'])->middleware('throttle:120,1')->name('payments.notify');
