<?php

namespace App\Modules\Media\Support;

use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Treatment;
use LogicException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

/**
 * Stores every media file under a tenant + clinic prefix so file access stays
 * tenant-isolated. Path prefixes are the S3/CloudFront access-scoping unit
 * (clinic staff → tenants/{t}/clinics/{c}/*; patient → users/{userId}/*).
 *
 * Owner-rooted taxonomy (locked at Faz-1, extended in Faz 2 — add branches here,
 * never move existing paths):
 *
 *   Clinic-owned (this feature, live now):
 *     tenants/{t}/clinics/{c}/{mediaId}/                         clinic branding (logo / cover / cover_mobile)
 *     tenants/{t}/clinics/{c}/patients/{p}/{mediaId}/            patient-level media
 *     tenants/{t}/clinics/{c}/patients/{p}/treatments/{tr}/…     treatment media
 *
 *   Patient/user-owned, cross-clinic (Faz 2 — NOT live yet):
 *     users/{userId}/{mediaId}/                                  avatar, Q&A uploads
 *     (clinics see a patient's Faz-2 file via per-file signed URLs, not a prefix grant)
 *
 * A clinic-scoped owner (one carrying clinic_id) with no branch here throws, so
 * future patient/treatment media can never be stored without the prefix (KVKK).
 */
final class TenantClinicPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return $this->basePath($media).'/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->basePath($media).'/conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->basePath($media).'/responsive-images/';
    }

    private function basePath(Media $media): string
    {
        $owner = $media->model;

        if ($owner instanceof Clinic) {
            return "tenants/{$owner->tenant_id}/clinics/{$owner->getKey()}/{$media->getKey()}";
        }

        if ($owner instanceof Doctor) {
            $clinic = $owner->clinic;

            return "tenants/{$clinic->tenant_id}/clinics/{$clinic->getKey()}/doctors/{$owner->getKey()}/{$media->getKey()}";
        }

        if ($owner instanceof Treatment) {
            $clinic = $owner->clinic;

            return "tenants/{$clinic->tenant_id}/clinics/{$clinic->getKey()}/patients/{$owner->patient_id}/treatments/{$owner->getKey()}/{$media->getKey()}";
        }

        if ($owner !== null && $owner->getAttribute('clinic_id') !== null) {
            throw new LogicException(sprintf(
                'Media path for clinic-scoped owner [%s] is not defined; add a branch to %s.',
                $owner::class,
                self::class,
            ));
        }

        return (string) $media->getKey();
    }
}
