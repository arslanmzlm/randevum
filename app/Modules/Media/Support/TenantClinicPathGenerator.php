<?php

namespace App\Modules\Media\Support;

use App\Models\Clinic;
use LogicException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

/**
 * Stores every media file under a tenant + clinic prefix so file access stays
 * tenant-isolated. Intended hierarchy (patient/treatment media arrive in Faz 2):
 *
 *   tenants/{t}/clinics/{c}/{media}                                                clinic branding (logo/cover)
 *   tenants/{t}/clinics/{c}/patients/{patientId}/{media}                           patient-level media
 *   tenants/{t}/clinics/{c}/patients/{patientId}/treatments/{treatmentId}/{media}  treatment media (nested under its patient)
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
