<?php

namespace App\Modules\Compliance\Repositories;

use App\Enums\LegalDocumentType;
use App\Models\Consent;
use App\Models\LegalDocument;

class ConsentRepository
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Consent
    {
        return Consent::create($data);
    }

    /**
     * Active platform-level (clinic_id IS NULL) documents for the given types, keyed by
     * type value. Signup has no clinic context, so only platform docs are in scope.
     *
     * @param  list<LegalDocumentType>  $types
     * @return array<string, LegalDocument>
     */
    public function activePlatformDocuments(array $types): array
    {
        return LegalDocument::withoutGlobalScopes()
            ->whereNull('clinic_id')
            ->whereIn('type', array_map(fn (LegalDocumentType $type): string => $type->value, $types))
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (LegalDocument $doc): string => $doc->type->value)
            ->all();
    }
}
