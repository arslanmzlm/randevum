<?php

use App\Modules\Catalog\Http\Controllers\ProductController;
use App\Modules\Catalog\Http\Controllers\ServiceController;
use App\Modules\Catalog\Http\Controllers\StockMovementController;
use App\Modules\Core\Http\Controllers\ClinicController;
use App\Modules\Core\Http\Controllers\DoctorController;
use App\Modules\Medical\Http\Controllers\AnamnesisController;
use App\Modules\Medical\Http\Controllers\CaseController;
use App\Modules\Medical\Http\Controllers\FollowUpController;
use App\Modules\Medical\Http\Controllers\FollowUpTypeController;
use App\Modules\Medical\Http\Controllers\PatientController;
use App\Modules\Medical\Http\Controllers\SegmentController;
use App\Modules\Medical\Http\Controllers\TagController;
use App\Modules\Medical\Http\Controllers\TreatmentController;
use App\Modules\Medical\Http\Controllers\TreatmentMediaController;
use App\Modules\Medical\Http\Controllers\TreatmentReportController;
use App\Modules\Scheduling\Http\Controllers\AppointmentController;
use App\Modules\Scheduling\Http\Controllers\AppointmentTypeController;
use App\Modules\Scheduling\Http\Controllers\CalendarController;
use App\Modules\Scheduling\Http\Controllers\ScheduleExceptionController;
use Illuminate\Support\Facades\Route;

// Clinic profile (authenticated owner).
Route::middleware('auth')->group(function () {
    Route::get('/clinic', [ClinicController::class, 'edit'])->name('clinic.edit');
    Route::put('/clinic', [ClinicController::class, 'update'])->name('clinic.update');
    Route::post('/clinic/media/{collection}', [ClinicController::class, 'updateMedia'])->name('clinic.media.update');
    Route::delete('/clinic/media/{collection}', [ClinicController::class, 'removeMedia'])->name('clinic.media.remove');
});

// Doctor management.
// Literal segments (create, me, self) are declared BEFORE {doctor} so they are
// not captured as a route-model-bound id.
Route::middleware('auth')->group(function () {
    Route::get('/doctors', [DoctorController::class, 'index'])->name('doctors.index');
    Route::get('/doctors/create', [DoctorController::class, 'create'])->name('doctors.create');
    Route::get('/doctors/me', [DoctorController::class, 'mine'])->name('doctors.mine');
    Route::post('/doctors', [DoctorController::class, 'store'])->name('doctors.store');
    Route::post('/doctors/self', [DoctorController::class, 'storeOwn'])->name('doctors.storeOwn');
    Route::get('/doctors/{doctor}/offboard-preview', [DoctorController::class, 'offboardPreview'])->middleware('throttle:60,1')->name('doctors.offboard.preview');
    Route::post('/doctors/{doctor}/offboard', [DoctorController::class, 'offboard'])->name('doctors.offboard');
    Route::get('/doctors/{doctor}/edit', [DoctorController::class, 'edit'])->name('doctors.edit');
    Route::put('/doctors/{doctor}', [DoctorController::class, 'update'])->name('doctors.update');
    Route::delete('/doctors/{doctor}', [DoctorController::class, 'destroy'])->name('doctors.destroy');
    Route::post('/doctors/{doctor}/avatar', [DoctorController::class, 'updateAvatar'])->name('doctors.avatar.update');
    Route::delete('/doctors/{doctor}/avatar', [DoctorController::class, 'removeAvatar'])->name('doctors.avatar.remove');
});

// Patient records.
// Literal segments (create) are declared BEFORE {patient} so they are
// not captured as a route-model-bound id.
Route::middleware('auth')->group(function () {
    Route::get('/patients', [PatientController::class, 'index'])->name('patients.index');
    Route::get('/patients/create', [PatientController::class, 'create'])->name('patients.create');
    Route::get('/patients/search', [PatientController::class, 'search'])->middleware('throttle:60,1')->name('patients.search');
    Route::post('/patients', [PatientController::class, 'store'])->name('patients.store');
    Route::get('/patients/{patient}', [PatientController::class, 'show'])->name('patients.show');
    Route::get('/patients/{patient}/edit', [PatientController::class, 'edit'])->name('patients.edit');
    Route::put('/patients/{patient}', [PatientController::class, 'update'])->name('patients.update');
    Route::delete('/patients/{patient}', [PatientController::class, 'destroy'])->name('patients.destroy');
    Route::patch('/patients/{patient}/notes', [PatientController::class, 'updateNotes'])->name('patients.notes.update');
    Route::put('/patients/{patient}/anamnesis', [AnamnesisController::class, 'update'])->name('patients.anamnesis.update');
    Route::get('/patients/{patient}/anamnesis/pdf', [AnamnesisController::class, 'pdf'])->name('patients.anamnesis.pdf');
    Route::post('/patients/{patient}/restore', [PatientController::class, 'restore'])->name('patients.restore');
    Route::put('/patients/{patient}/tags', [PatientController::class, 'syncTags'])->name('patients.tags.sync');
});

// Tag management (clinic-curated CRM tags).
Route::middleware('auth')->group(function () {
    Route::get('/tags', [TagController::class, 'index'])->name('tags.index');
    Route::post('/tags', [TagController::class, 'store'])->name('tags.store');
    Route::put('/tags/{tag}', [TagController::class, 'update'])->name('tags.update');
    Route::delete('/tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');
});

// Patient segments (saved filter presets).
Route::middleware('auth')->group(function () {
    Route::post('/patient-segments', [SegmentController::class, 'store'])->name('patient-segments.store');
    Route::delete('/patient-segments/{segment}', [SegmentController::class, 'destroy'])->name('patient-segments.destroy');
});

// Service catalog.
// Literal segments (create) are declared BEFORE {service} so they are
// not captured as a route-model-bound id.
Route::middleware('auth')->group(function () {
    Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
    Route::get('/services/create', [ServiceController::class, 'create'])->name('services.create');
    Route::post('/services', [ServiceController::class, 'store'])->name('services.store');
    Route::get('/services/{service}/edit', [ServiceController::class, 'edit'])->name('services.edit');
    Route::put('/services/{service}', [ServiceController::class, 'update'])->name('services.update');
    Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');
});

// Appointment types (settings / CRUD).
// Literal segment (create) declared BEFORE {appointmentType} so it is not captured as a bound id.
Route::middleware('auth')->group(function () {
    Route::get('/appointment-types', [AppointmentTypeController::class, 'index'])->name('appointment-types.index');
    Route::get('/appointment-types/create', [AppointmentTypeController::class, 'create'])->name('appointment-types.create');
    Route::post('/appointment-types', [AppointmentTypeController::class, 'store'])->name('appointment-types.store');
    Route::get('/appointment-types/{appointmentType}/edit', [AppointmentTypeController::class, 'edit'])->name('appointment-types.edit');
    Route::put('/appointment-types/{appointmentType}', [AppointmentTypeController::class, 'update'])->name('appointment-types.update');
    Route::delete('/appointment-types/{appointmentType}', [AppointmentTypeController::class, 'destroy'])->name('appointment-types.destroy');
});

// Calendar — month / week / day view of the clinic's appointments (read-only).
// Literal segment (events) declared before any future {calendar} wildcard.
Route::middleware('auth')->group(function () {
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::get('/calendar/events', [CalendarController::class, 'events'])->middleware('throttle:60,1')->name('calendar.events');
});

// Appointments — manual/walk-in creation + pre-check probes + lifecycle actions.
// Literal segments (index, create, availability, day-schedule, bulk-cancel, bulk-create) are
// declared BEFORE {appointment} so they are not captured as route-model-bound ids.
Route::middleware('auth')->group(function () {
    Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
    Route::get('/appointments/create', [AppointmentController::class, 'create'])->name('appointments.create');
    Route::get('/appointments/availability', [AppointmentController::class, 'availability'])->middleware('throttle:60,1')->name('appointments.availability');
    Route::get('/appointments/day-schedule', [AppointmentController::class, 'daySchedule'])->middleware('throttle:60,1')->name('appointments.day-schedule');
    Route::get('/appointments/upcoming', [AppointmentController::class, 'upcoming'])->middleware('throttle:60,1')->name('appointments.upcoming');
    Route::get('/appointments/bulk-cancel', [AppointmentController::class, 'bulkCancelPage'])->name('appointments.bulk-cancel');
    Route::get('/appointments/bulk-cancel/preview', [AppointmentController::class, 'bulkCancelPreview'])->middleware('throttle:60,1')->name('appointments.bulk-cancel.preview');
    Route::post('/appointments/bulk-cancel', [AppointmentController::class, 'bulkCancel'])->name('appointments.bulk-cancel.store');
    Route::get('/appointments/bulk-create', [AppointmentController::class, 'bulkCreatePage'])->name('appointments.bulk-create');
    Route::post('/appointments/bulk-create/precheck', [AppointmentController::class, 'bulkPrecheck'])->middleware('throttle:60,1')->name('appointments.bulk-create.precheck');
    Route::post('/appointments/bulk-create', [AppointmentController::class, 'bulkStore'])->name('appointments.bulk-create.store');
    Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
    // {appointment} must come after every literal /appointments/<word> route above,
    // otherwise a literal like /appointments/create would bind as an id.
    Route::get('/appointments/{appointment}', [AppointmentController::class, 'show'])->name('appointments.show');
    Route::get('/appointments/{appointment}/edit', [AppointmentController::class, 'edit'])->name('appointments.edit');
    Route::put('/appointments/{appointment}', [AppointmentController::class, 'update'])->name('appointments.update');
    Route::patch('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])->name('appointments.cancel');
    Route::patch('/appointments/{appointment}/arrive', [AppointmentController::class, 'arrive'])->name('appointments.arrive');
    Route::patch('/appointments/{appointment}/no-show', [AppointmentController::class, 'noShow'])->name('appointments.no-show');
    Route::post('/appointments/{appointment}/send-reminder', [AppointmentController::class, 'sendReminder'])->name('appointments.send-reminder');
    Route::delete('/appointments/{appointment}', [AppointmentController::class, 'destroy'])->name('appointments.destroy');
});

// Doctor availability / schedule exceptions.
Route::middleware('auth')->group(function () {
    Route::get('/schedule-exceptions', [ScheduleExceptionController::class, 'index'])->name('schedule-exceptions.index');
    Route::post('/schedule-exceptions', [ScheduleExceptionController::class, 'store'])->name('schedule-exceptions.store');
    Route::delete('/schedule-exceptions/{scheduleException}', [ScheduleExceptionController::class, 'destroy'])->name('schedule-exceptions.destroy');
});

// Case management.
// Collection routes (index, store) are declared BEFORE {case} so they are
// not captured as a route-model-bound id.
Route::middleware('auth')->group(function () {
    Route::get('/cases', [CaseController::class, 'index'])->name('cases.index');
    Route::post('/cases', [CaseController::class, 'store'])->name('cases.store');
    Route::get('/cases/{case}', [CaseController::class, 'show'])->name('cases.show');
    Route::patch('/cases/{case}/status', [CaseController::class, 'changeStatus'])->name('cases.status.update');
    Route::patch('/cases/{case}/notes', [CaseController::class, 'updateNotes'])->name('cases.notes.update');
    Route::patch('/cases/{case}/title', [CaseController::class, 'updateTitle'])->name('cases.title.update');
    Route::post('/cases/{case}/treatments', [CaseController::class, 'linkTreatments'])->name('cases.treatments.link');
    Route::delete('/cases/{case}/treatments/{treatment}', [CaseController::class, 'unlinkTreatment'])->name('cases.treatments.unlink');
});

// Follow-up records (call list + manual creation + completion). Literal segment (cases,
// the case-picker JSON endpoint) declared BEFORE {followUp} so it is not captured as a
// bound id.
Route::middleware('auth')->group(function () {
    Route::get('/follow-ups/cases', [FollowUpController::class, 'casesForPatient'])->middleware('throttle:60,1')->name('follow-ups.cases');
    Route::post('/follow-ups', [FollowUpController::class, 'store'])->name('follow-ups.store');
    Route::patch('/follow-ups/{followUp}/complete', [FollowUpController::class, 'complete'])->name('follow-ups.complete');
    Route::patch('/follow-ups/{followUp}/cancel', [FollowUpController::class, 'cancel'])->name('follow-ups.cancel');
});

// Follow-up types (settings / CRUD).
Route::middleware('auth')->group(function () {
    Route::get('/follow-up-types', [FollowUpTypeController::class, 'index'])->name('follow-up-types.index');
    Route::post('/follow-up-types', [FollowUpTypeController::class, 'store'])->name('follow-up-types.store');
    Route::put('/follow-up-types/{followUpType}', [FollowUpTypeController::class, 'update'])->name('follow-up-types.update');
    Route::delete('/follow-up-types/{followUpType}', [FollowUpTypeController::class, 'destroy'])->name('follow-up-types.destroy');
});

// Treatment lifecycle — collection route (index) before {treatment}, literal segments
// (process) before {treatment} too.
Route::middleware('auth')->group(function () {
    Route::get('/treatments', [TreatmentController::class, 'index'])->name('treatments.index');
    Route::post('/appointments/{appointment}/treatment', [TreatmentController::class, 'start'])->name('treatments.start');
    Route::get('/treatments/{treatment}/process', [TreatmentController::class, 'process'])->name('treatments.process');
    Route::put('/treatments/{treatment}/complete', [TreatmentController::class, 'complete'])->name('treatments.complete');
    Route::post('/treatments/{treatment}/void', [TreatmentController::class, 'void'])->name('treatments.void');
    Route::get('/treatments/{treatment}/report', [TreatmentReportController::class, 'show'])->name('treatments.report');
    Route::get('/treatments/{treatment}', [TreatmentController::class, 'show'])->name('treatments.show');
    Route::post('/treatments/{treatment}/media', [TreatmentMediaController::class, 'store'])->name('treatments.media.store');
    Route::get('/treatments/{treatment}/media/{media}', [TreatmentMediaController::class, 'show'])->name('treatments.media.show');
    Route::delete('/treatments/{treatment}/media/{media}', [TreatmentMediaController::class, 'destroy'])->name('treatments.media.destroy');
});

// Product catalog.
// Literal segments (create) are declared BEFORE {product} so they are
// not captured as a route-model-bound id.
Route::middleware('auth')->group(function () {
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/{product}/movements', [StockMovementController::class, 'index'])->name('products.movements.index');
    Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
    Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::patch('/products/{product}/stock', [ProductController::class, 'updateStock'])->name('products.stock.update');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
});
