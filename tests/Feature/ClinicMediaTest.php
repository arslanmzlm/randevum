<?php

use App\Models\Clinic;
use App\Models\Patient;
use App\Modules\Media\Support\TenantClinicPathGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake(config('media-library.disk_name'));
});

it('registers large/medium/thumb conversions', function (): void {
    $clinic = Clinic::factory()->create();
    $clinic->registerAllMediaConversions();

    $names = collect($clinic->mediaConversions)->map->getName();

    expect($names)->toContain('large', 'medium', 'thumb');
});

it('stores clinic media under a tenant/clinic path prefix', function (): void {
    Queue::fake(); // skip queued conversion jobs

    $clinic = Clinic::factory()->create();

    $media = $clinic
        ->addMedia(UploadedFile::fake()->image('logo.png', 600, 600))
        ->toMediaCollection('logo');

    expect($media->getPathRelativeToRoot())
        ->toStartWith("tenants/{$clinic->tenant_id}/clinics/{$clinic->id}/");
});

it('keeps only one file in a single-file collection', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $clinic->addMedia(UploadedFile::fake()->image('a.png'))->toMediaCollection('logo');
    $clinic->addMedia(UploadedFile::fake()->image('b.png'))->toMediaCollection('logo');

    expect($clinic->getMedia('logo'))->toHaveCount(1);
});

it('refuses media for a clinic-scoped owner without a defined path branch', function (): void {
    $patient = Patient::factory()->create();

    $media = new Media;
    $media->model_type = $patient->getMorphClass();
    $media->model_id = $patient->getKey();

    expect(fn () => (new TenantClinicPathGenerator)->getPath($media))
        ->toThrow(LogicException::class);
});
