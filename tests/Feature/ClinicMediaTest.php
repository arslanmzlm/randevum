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

it('registers logo_dark as a single-file collection', function (): void {
    Queue::fake();

    // A fresh Clinic per collection: calling getMedia() caches the model's `media`
    // relation, and a later addMedia() on a DIFFERENT collection saved through the
    // same cached instance would trim against that stale snapshot.
    $clinic = Clinic::factory()->create();
    $clinic->addMedia(UploadedFile::fake()->image('dark-a.png'))->toMediaCollection('logo_dark');
    $clinic->addMedia(UploadedFile::fake()->image('dark-b.png'))->toMediaCollection('logo_dark');

    expect($clinic->getMedia('logo_dark'))->toHaveCount(1);
});

it('registers logo_icon as a single-file collection', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $clinic->addMedia(UploadedFile::fake()->image('icon-a.png'))->toMediaCollection('logo_icon');
    $clinic->addMedia(UploadedFile::fake()->image('icon-b.png'))->toMediaCollection('logo_icon');

    expect($clinic->getMedia('logo_icon'))->toHaveCount(1);
});

it('registers large/medium/thumb conversions for logo_dark and logo_icon', function (): void {
    $clinic = Clinic::factory()->create();
    $clinic->registerAllMediaConversions();

    $forCollection = fn (string $collection) => collect($clinic->mediaConversions)
        ->filter(fn ($conversion) => in_array($collection, $conversion->getPerformOnCollections(), true))
        ->map->getName();

    expect($forCollection('logo_dark'))->toContain('large', 'medium', 'thumb')
        ->and($forCollection('logo_icon'))->toContain('large', 'medium', 'thumb');
});

it('stores logo_dark and logo_icon media under the same tenant/clinic path prefix', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();

    $darkMedia = $clinic->addMedia(UploadedFile::fake()->image('dark.png', 600, 600))
        ->toMediaCollection('logo_dark');
    $iconMedia = $clinic->addMedia(UploadedFile::fake()->image('icon.png', 600, 600))
        ->toMediaCollection('logo_icon');

    expect($darkMedia->getPathRelativeToRoot())
        ->toStartWith("tenants/{$clinic->tenant_id}/clinics/{$clinic->id}/")
        ->and($iconMedia->getPathRelativeToRoot())
        ->toStartWith("tenants/{$clinic->tenant_id}/clinics/{$clinic->id}/");
});

it('Clinic::logoUrl falls back to the base logo, then to null', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();

    expect($clinic->logoUrl('logo_icon'))->toBeNull();

    $clinic->addMedia(UploadedFile::fake()->image('logo.png', 600, 600))->toMediaCollection('logo');
    $clinic->refresh();
    expect($clinic->logoUrl('logo_icon'))->toBe($clinic->imageUrl('logo', 'medium'));

    $clinic->addMedia(UploadedFile::fake()->image('icon.png', 600, 600))->toMediaCollection('logo_icon');
    $clinic->refresh();
    expect($clinic->logoUrl('logo_icon'))->toBe($clinic->imageUrl('logo_icon', 'medium'))
        ->and($clinic->logoUrl('logo_icon'))->not->toBe($clinic->imageUrl('logo', 'medium'));
});

it('refuses media for a clinic-scoped owner without a defined path branch', function (): void {
    $patient = Patient::factory()->create();

    $media = new Media;
    $media->model_type = $patient->getMorphClass();
    $media->model_id = $patient->getKey();

    expect(fn () => (new TenantClinicPathGenerator)->getPath($media))
        ->toThrow(LogicException::class);
});
