<?php

namespace App\Modules\Compliance\Services;

use App\Models\User;
use App\Modules\Compliance\Contracts\ConsentRecorderContract;
use App\Modules\Compliance\Exceptions\MissingActiveLegalDocumentException;
use App\Modules\Compliance\Repositories\ConsentRepository;

class ConsentService implements ConsentRecorderContract
{
    public function __construct(private ConsentRepository $repository) {}

    /**
     * {@inheritDoc}
     */
    public function recordRegistrationConsents(User $user, ?string $ipAddress, ?string $userAgent): void
    {
        $documents = $this->repository->activePlatformDocuments(self::REGISTRATION_DOCUMENT_TYPES);

        foreach (self::REGISTRATION_DOCUMENT_TYPES as $type) {
            $document = $documents[$type->value] ?? null;

            if ($document === null) {
                throw new MissingActiveLegalDocumentException($type->value);
            }

            $this->repository->create([
                // Platform-level: this is the owner↔platform consent, not clinic-scoped.
                'clinic_id' => null,
                'legal_document_id' => $document->id,
                'consentable_type' => 'user',
                'consentable_id' => $user->id,
                'accepted_at' => now(),
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                // The owner accepts on their own behalf at signup.
                'accepted_by_user_id' => $user->id,
            ]);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function activeRegistrationDocuments(): array
    {
        return $this->repository->activePlatformDocuments(self::REGISTRATION_DOCUMENT_TYPES);
    }
}
