<?php

use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\CaseRecord;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Modules\Medical\Http\Requests\StoreTreatmentMediaRequest;
use App\Support\ClinicContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
    Storage::fake('media_private');
});

/**
 * Assign a clinic-scoped role (treatment-media tests).
 */
function tmRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Full clinic setup: one user per role + a doctor profile + a patient + a Draft
 * treatment owned by that doctor.
 *
 * @return array{clinic: Clinic, owner: User, manager: User, receptionist: User,
 *     assistant: User, doctorUser: User, doctor: Doctor, patient: Patient, treatment: Treatment}
 */
function tmSetup(): array
{
    $clinic = Clinic::factory()->create();

    $owner = User::factory()->create();
    tmRole($owner, 'owner', $clinic->id);

    $manager = User::factory()->create();
    tmRole($manager, 'manager', $clinic->id);

    $receptionist = User::factory()->create();
    tmRole($receptionist, 'receptionist', $clinic->id);

    $assistant = User::factory()->create();
    tmRole($assistant, 'assistant', $clinic->id);

    $doctorUser = User::factory()->create();
    tmRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
    ]);

    $treatment = Treatment::factory()->draft()->create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
    ]);

    return compact('clinic', 'owner', 'manager', 'receptionist', 'assistant', 'doctorUser', 'doctor', 'patient', 'treatment');
}

/**
 * A minimal byte sequence real content-sniffers (libmagic) recognize as a PDF.
 */
function tmFakePdf(string $name = 'rapor.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        "%PDF-1.4\n%\xC3\xA2\xC3\xA3\xC3\x8F\xC3\x93\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF",
    );
}

/**
 * A real (tiny) HEIC image, generated via Imagick, so content-sniffing genuinely
 * detects image/heic — proving the end-to-end upload path for the feature's
 * central use case (browsers can't render HEIC; the conversion pipeline needs a
 * correctly-detected image to kick in).
 */
function tmFakeHeic(string $name = 'foot.heic'): UploadedFile
{
    $image = new Imagick;
    $image->newImage(20, 20, new ImagickPixel('red'));
    $image->setImageFormat('heic');

    return UploadedFile::fake()->createWithContent($name, $image->getImageBlob());
}

// ---------------------------------------------------------------------------
// Upload — happy path
// ---------------------------------------------------------------------------

it('doctor uploads an image to their own draft treatment, stored under the tenant/clinic/patient/treatment prefix with the caption persisted', function (): void {
    Queue::fake();
    ['clinic' => $clinic, 'doctorUser' => $doctorUser, 'treatment' => $treatment] = tmSetup();

    $this->actingAs($doctorUser)
        ->post(route('treatments.media.store', $treatment), [
            'file' => UploadedFile::fake()->image('foot.jpg', 800, 600),
            'caption' => '2. seans öncesi sol ayak',
        ])
        ->assertRedirect();

    $media = Media::query()->where('collection_name', 'treatment_media')->first();

    expect($media)->not->toBeNull()
        ->and($media->disk)->toBe('media_private')
        ->and($media->model_type)->toBe($treatment->getMorphClass())
        ->and($media->model_id)->toBe($treatment->id)
        ->and($media->getCustomProperty('caption'))->toBe('2. seans öncesi sol ayak')
        ->and($media->getPathRelativeToRoot())->toStartWith(
            "tenants/{$clinic->tenant_id}/clinics/{$clinic->id}/patients/{$treatment->patient_id}/treatments/{$treatment->id}/"
        );
});

it('doctor uploads a pdf document to their own draft treatment with no caption', function (): void {
    Queue::fake();
    ['doctorUser' => $doctorUser, 'treatment' => $treatment] = tmSetup();

    $this->actingAs($doctorUser)
        ->post(route('treatments.media.store', $treatment), ['file' => tmFakePdf()])
        ->assertRedirect();

    $media = Media::query()->where('collection_name', 'treatment_media')->first();

    expect($media)->not->toBeNull()
        ->and($media->mime_type)->toBe('application/pdf')
        ->and($media->getCustomProperty('caption'))->toBeNull();
});

it('uploads a genuine HEIC image end to end (real content correctly detected as image/heic)', function (): void {
    Queue::fake();
    ['doctorUser' => $doctorUser, 'treatment' => $treatment] = tmSetup();

    $this->actingAs($doctorUser)
        ->post(route('treatments.media.store', $treatment), ['file' => tmFakeHeic()])
        ->assertRedirect();

    $media = Media::query()->where('collection_name', 'treatment_media')->first();

    expect($media)->not->toBeNull()
        ->and($media->mime_type)->toBe('image/heic');
});

it('assistant can upload, view and delete treatment media', function (): void {
    Queue::fake();
    ['assistant' => $assistant, 'treatment' => $treatment] = tmSetup();

    $this->actingAs($assistant)
        ->post(route('treatments.media.store', $treatment), ['file' => UploadedFile::fake()->image('foot.jpg')])
        ->assertRedirect();

    $media = Media::query()->where('collection_name', 'treatment_media')->first();

    $this->actingAs($assistant)
        ->get(route('treatments.media.show', ['treatment' => $treatment, 'media' => $media]))
        ->assertOk();

    $this->actingAs($assistant)
        ->delete(route('treatments.media.destroy', ['treatment' => $treatment, 'media' => $media]))
        ->assertRedirect();

    expect(Media::query()->find($media->id))->toBeNull();
});

// ---------------------------------------------------------------------------
// Authorization — owner / manager / receptionist excluded (KVKK-min)
// ---------------------------------------------------------------------------

it('owner, manager and receptionist get 403 on store/show/destroy of treatment media', function (string $role): void {
    Queue::fake();
    $setup = tmSetup();
    $treatment = $setup['treatment'];

    // Seed one media item as the doctor first, so show/destroy have a real target.
    $this->actingAs($setup['doctorUser'])
        ->post(route('treatments.media.store', $treatment), ['file' => UploadedFile::fake()->image('foot.jpg')]);
    $media = Media::query()->where('collection_name', 'treatment_media')->first();

    $user = $setup[$role];

    $this->actingAs($user)
        ->post(route('treatments.media.store', $treatment), ['file' => UploadedFile::fake()->image('other.jpg')])
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('treatments.media.show', ['treatment' => $treatment, 'media' => $media]))
        ->assertForbidden();

    $this->actingAs($user)
        ->delete(route('treatments.media.destroy', ['treatment' => $treatment, 'media' => $media]))
        ->assertForbidden();

    // Untouched: still exactly the one media item created by the doctor.
    expect(Media::query()->where('collection_name', 'treatment_media')->count())->toBe(1);
})->with([
    'owner' => 'owner',
    'manager' => 'manager',
    'receptionist' => 'receptionist',
]);

it("doctor gets 403 on a colleague's treatment media (lacks treatments.viewAll)", function (): void {
    Queue::fake();
    $setup = tmSetup();

    $otherDoctorUser = User::factory()->create();
    tmRole($otherDoctorUser, 'doctor', $setup['clinic']->id);
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $setup['clinic']->id, 'user_id' => $otherDoctorUser->id]);
    $otherPatient = Patient::factory()->create(['clinic_id' => $setup['clinic']->id]);
    $otherTreatment = Treatment::factory()->draft()->create([
        'clinic_id' => $setup['clinic']->id,
        'doctor_id' => $otherDoctor->id,
        'patient_id' => $otherPatient->id,
    ]);

    $this->actingAs($setup['doctorUser'])
        ->post(route('treatments.media.store', $otherTreatment), ['file' => UploadedFile::fake()->image('foot.jpg')])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Validation — formats & size
// ---------------------------------------------------------------------------

it('rejects an SVG upload', function (): void {
    ['doctorUser' => $doctorUser, 'treatment' => $treatment] = tmSetup();

    $this->actingAs($doctorUser)
        ->post(route('treatments.media.store', $treatment), [
            'file' => UploadedFile::fake()->create('icon.svg', 10, 'image/svg+xml'),
        ])
        ->assertSessionHasErrors('file');

    expect(Media::query()->where('collection_name', 'treatment_media')->exists())->toBeFalse();
});

it('rejects an audio upload', function (): void {
    ['doctorUser' => $doctorUser, 'treatment' => $treatment] = tmSetup();

    $this->actingAs($doctorUser)
        ->post(route('treatments.media.store', $treatment), [
            'file' => UploadedFile::fake()->create('memo.mp3', 100, 'audio/mpeg'),
        ])
        ->assertSessionHasErrors('file');
});

it('rejects a video upload', function (): void {
    ['doctorUser' => $doctorUser, 'treatment' => $treatment] = tmSetup();

    $this->actingAs($doctorUser)
        ->post(route('treatments.media.store', $treatment), [
            'file' => UploadedFile::fake()->create('clip.mp4', 100, 'video/mp4'),
        ])
        ->assertSessionHasErrors('file');
});

it('rejects a file over 50MB', function (): void {
    ['doctorUser' => $doctorUser, 'treatment' => $treatment] = tmSetup();

    $this->actingAs($doctorUser)
        ->post(route('treatments.media.store', $treatment), [
            'file' => UploadedFile::fake()->create('huge.jpg', 51 * 1024, 'image/jpeg'),
        ])
        ->assertSessionHasErrors('file');
});

it('accepts a HEIC file at the validation layer even when the reported MIME is unrecognized (application/octet-stream)', function (): void {
    $validator = Validator::make(
        ['file' => UploadedFile::fake()->create('foot.heic', 500, 'application/octet-stream')],
        (new StoreTreatmentMediaRequest)->rules(),
    );

    expect($validator->passes())->toBeTrue();
});

it('accepts a HEIF file at the validation layer even when the reported MIME is unrecognized', function (): void {
    $validator = Validator::make(
        ['file' => UploadedFile::fake()->create('foot.heif', 500, 'application/octet-stream')],
        (new StoreTreatmentMediaRequest)->rules(),
    );

    expect($validator->passes())->toBeTrue();
});

it('accepts docx and xlsx at the validation layer', function (string $name, string $mime): void {
    $validator = Validator::make(
        ['file' => UploadedFile::fake()->create($name, 500, $mime)],
        (new StoreTreatmentMediaRequest)->rules(),
    );

    expect($validator->passes())->toBeTrue();
})->with([
    'docx' => ['recete.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'xlsx' => ['takip.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
]);

it('rejects a renamed file whose extension is allowed but the real MIME is not (non-HEIC formats still require both checks)', function (): void {
    // .jpg extension but a real MIME that is not one of the allowed image/document types.
    $validator = Validator::make(
        ['file' => UploadedFile::fake()->create('renamed.jpg', 10, 'application/x-msdownload')],
        (new StoreTreatmentMediaRequest)->rules(),
    );

    expect($validator->passes())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Streaming (show) — authorized, never a public URL; 404 on treatment/media mismatch
// ---------------------------------------------------------------------------

it('streams treatment media inline for an authorized viewer', function (): void {
    Queue::fake();
    ['doctorUser' => $doctorUser, 'treatment' => $treatment] = tmSetup();

    $this->actingAs($doctorUser)
        ->post(route('treatments.media.store', $treatment), ['file' => UploadedFile::fake()->image('foot.jpg')]);
    $media = Media::query()->where('collection_name', 'treatment_media')->first();

    $this->actingAs($doctorUser)
        ->get(route('treatments.media.show', ['treatment' => $treatment, 'media' => $media]))
        ->assertOk();
});

it('returns 404 when the media does not belong to the given treatment', function (): void {
    Queue::fake();
    $setup = tmSetup();

    $this->actingAs($setup['doctorUser'])
        ->post(route('treatments.media.store', $setup['treatment']), ['file' => UploadedFile::fake()->image('foot.jpg')]);
    $media = Media::query()->where('collection_name', 'treatment_media')->first();

    $otherPatient = Patient::factory()->create(['clinic_id' => $setup['clinic']->id]);
    $otherTreatment = Treatment::factory()->draft()->create([
        'clinic_id' => $setup['clinic']->id,
        'doctor_id' => $setup['doctor']->id,
        'patient_id' => $otherPatient->id,
    ]);

    $this->actingAs($setup['doctorUser'])
        ->get(route('treatments.media.show', ['treatment' => $otherTreatment, 'media' => $media]))
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// Delete window (48h correction window)
// ---------------------------------------------------------------------------

it('hard-deletes within the 48h delete window (file removed from disk)', function (): void {
    Queue::fake();
    ['doctorUser' => $doctorUser, 'treatment' => $treatment] = tmSetup();

    $this->actingAs($doctorUser)
        ->post(route('treatments.media.store', $treatment), ['file' => UploadedFile::fake()->image('foot.jpg')]);
    $media = Media::query()->where('collection_name', 'treatment_media')->first();
    $path = $media->getPathRelativeToRoot();

    $this->actingAs($doctorUser)
        ->delete(route('treatments.media.destroy', ['treatment' => $treatment, 'media' => $media]))
        ->assertRedirect();

    expect(Media::query()->find($media->id))->toBeNull()
        ->and(Storage::disk('media_private')->exists($path))->toBeFalse();
});

it('blocks delete after the 48h delete window has expired', function (): void {
    Queue::fake();
    ['doctorUser' => $doctorUser, 'treatment' => $treatment] = tmSetup();

    $this->actingAs($doctorUser)
        ->post(route('treatments.media.store', $treatment), ['file' => UploadedFile::fake()->image('foot.jpg')]);
    $media = Media::query()->where('collection_name', 'treatment_media')->first();

    $windowSeconds = (int) config('platform.media.delete_window');
    $media->created_at = now()->subSeconds($windowSeconds + 60);
    $media->save();

    $this->actingAs($doctorUser)
        ->delete(route('treatments.media.destroy', ['treatment' => $treatment, 'media' => $media]))
        ->assertSessionHasErrors('media');

    expect(Media::query()->find($media->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (mandatory)
// ---------------------------------------------------------------------------

it('clinic A cannot stream clinic B treatment media (404)', function (): void {
    Queue::fake();
    $setupA = tmSetup();
    $setupB = tmSetup();

    $this->actingAs($setupB['doctorUser'])
        ->post(route('treatments.media.store', $setupB['treatment']), ['file' => UploadedFile::fake()->image('foot.jpg')]);
    $mediaB = Media::query()->where('collection_name', 'treatment_media')->first();

    $this->actingAs($setupA['doctorUser'])
        ->get(route('treatments.media.show', ['treatment' => $setupB['treatment'], 'media' => $mediaB]))
        ->assertNotFound();
});

it('clinic A cannot delete clinic B treatment media (404) and the file survives', function (): void {
    Queue::fake();
    $setupA = tmSetup();
    $setupB = tmSetup();

    $this->actingAs($setupB['doctorUser'])
        ->post(route('treatments.media.store', $setupB['treatment']), ['file' => UploadedFile::fake()->image('foot.jpg')]);
    $mediaB = Media::query()->where('collection_name', 'treatment_media')->first();

    $this->actingAs($setupA['doctorUser'])
        ->delete(route('treatments.media.destroy', ['treatment' => $setupB['treatment'], 'media' => $mediaB]))
        ->assertNotFound();

    expect(Media::query()->find($mediaB->id))->not->toBeNull();
});

it('clinic A cannot upload media to a clinic B treatment (404 via ClinicScope route binding)', function (): void {
    $setupA = tmSetup();
    $setupB = tmSetup();

    $this->actingAs($setupA['doctorUser'])
        ->post(route('treatments.media.store', $setupB['treatment']), ['file' => UploadedFile::fake()->image('foot.jpg')])
        ->assertNotFound();

    expect(Media::query()->where('collection_name', 'treatment_media')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Inertia prop contract — Process / Show gated on treatments.media.view
// ---------------------------------------------------------------------------

it('includes treatment.media on the Process page for a doctor (own draft treatment)', function (): void {
    Queue::fake();
    ['doctorUser' => $doctorUser, 'treatment' => $treatment] = tmSetup();

    $this->actingAs($doctorUser)
        ->post(route('treatments.media.store', $treatment), ['file' => UploadedFile::fake()->image('foot.jpg')]);

    $this->actingAs($doctorUser)
        ->get(route('treatments.process', $treatment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('treatments/Process')
            ->has('treatment.media', 1)
        );
});

it('omits treatment.media on the Process page for a receptionist (lacks treatments.media.view)', function (): void {
    Queue::fake();
    $setup = tmSetup();

    $this->actingAs($setup['doctorUser'])
        ->post(route('treatments.media.store', $setup['treatment']), ['file' => UploadedFile::fake()->image('foot.jpg')]);

    // Receptionist holds treatments.viewAll so the Process screen itself is reachable;
    // treatments.media.view is what's missing.
    $this->actingAs($setup['receptionist'])
        ->get(route('treatments.process', $setup['treatment']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('treatments/Process')
            ->missing('treatment.media')
        );
});

it('includes treatment.media on the Show page for an assistant', function (): void {
    Queue::fake();
    $setup = tmSetup();

    $this->actingAs($setup['doctorUser'])
        ->post(route('treatments.media.store', $setup['treatment']), ['file' => UploadedFile::fake()->image('foot.jpg')]);

    $this->actingAs($setup['assistant'])
        ->get(route('treatments.show', $setup['treatment']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('treatments/Show')
            ->has('treatment.media', 1)
        );
});

it('omits treatment.media on the Show page for an owner (lacks treatments.media.view)', function (): void {
    Queue::fake();
    $setup = tmSetup();

    $this->actingAs($setup['doctorUser'])
        ->post(route('treatments.media.store', $setup['treatment']), ['file' => UploadedFile::fake()->image('foot.jpg')]);

    $this->actingAs($setup['owner'])
        ->get(route('treatments.show', $setup['treatment']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('treatments/Show')
            ->missing('treatment.media')
        );
});

// ---------------------------------------------------------------------------
// Case rollup — read-only, aggregated across the case's treatments
// ---------------------------------------------------------------------------

it('rolls up treatment media on the Case Show page, read-only, for an assistant', function (): void {
    Queue::fake();
    $setup = tmSetup();

    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $setup['clinic']->id,
        'patient_id' => $setup['patient']->id,
        'doctor_id' => $setup['doctor']->id,
        'vertical_id' => $setup['clinic']->vertical_id,
    ]);

    // Media is attached while each treatment is Draft (the only upload surface),
    // then the treatment is completed — the rollup still surfaces it read-only.
    $treatment2 = Treatment::factory()->draft()->create([
        'clinic_id' => $setup['clinic']->id,
        'doctor_id' => $setup['doctor']->id,
        'patient_id' => $setup['patient']->id,
        'case_id' => $case->id,
    ]);
    Treatment::withoutGlobalScopes()->where('id', $setup['treatment']->id)->update(['case_id' => $case->id]);

    $this->actingAs($setup['doctorUser'])
        ->post(route('treatments.media.store', $setup['treatment']), ['file' => UploadedFile::fake()->image('a.jpg')]);
    $this->actingAs($setup['doctorUser'])
        ->post(route('treatments.media.store', $treatment2), ['file' => UploadedFile::fake()->image('b.jpg')]);

    $treatment2->update(['status' => TreatmentStatus::Completed->value, 'completed_at' => now()]);

    $this->actingAs($setup['assistant'])
        ->get(route('cases.show', $case))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('cases/Show')
            ->has('case.media', 2)
        );
});

it('rejects uploading media to a completed treatment at the endpoint (Draft-only surface)', function (): void {
    Queue::fake();
    $setup = tmSetup();

    $completed = Treatment::factory()->completed()->create([
        'clinic_id' => $setup['clinic']->id,
        'doctor_id' => $setup['doctor']->id,
        'patient_id' => $setup['patient']->id,
    ]);

    $this->actingAs($setup['doctorUser'])
        ->post(route('treatments.media.store', $completed), ['file' => UploadedFile::fake()->image('foot.jpg')])
        ->assertForbidden();

    expect(Media::query()->where('collection_name', 'treatment_media')->exists())->toBeFalse();
});

it('omits case.media on the Case Show page for a role without treatments.media.view', function (): void {
    $setup = tmSetup();

    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $setup['clinic']->id,
        'patient_id' => $setup['patient']->id,
        'doctor_id' => $setup['doctor']->id,
        'vertical_id' => $setup['clinic']->vertical_id,
    ]);

    $this->actingAs($setup['owner'])
        ->get(route('cases.show', $case))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('cases/Show')
            ->missing('case.media')
        );
});
