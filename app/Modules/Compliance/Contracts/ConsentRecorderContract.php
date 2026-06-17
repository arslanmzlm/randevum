<?php

namespace App\Modules\Compliance\Contracts;

use App\Enums\LegalDocumentType;
use App\Models\LegalDocument;
use App\Models\User;
use App\Modules\Compliance\Exceptions\MissingActiveLegalDocumentException;

/**
 * Cross-module seam: Identity calls this to record a clinic owner's acceptance of the
 * platform legal documents at signup, without importing Compliance internals.
 * Bound to ConsentService in ComplianceServiceProvider.
 */
interface ConsentRecorderContract
{
    /**
     * Platform documents the owner accepts at registration, in display order. These are
     * the platform's liability shield: the Data Processing Agreement makes the platform a
     * processor and contractually pushes data-misuse risk onto the clinic (the controller).
     *
     * @var list<LegalDocumentType>
     */
    public const REGISTRATION_DOCUMENT_TYPES = [
        LegalDocumentType::TermsOfService,
        LegalDocumentType::PrivacyPolicy,
        LegalDocumentType::DataProcessingAgreement,
    ];

    /**
     * Write one immutable consent audit row per active platform registration document
     * (consentable_type='user'). Must run inside the registration transaction so a failure
     * rolls the whole signup back — an owner must never exist without recorded consent.
     *
     * @throws MissingActiveLegalDocumentException when a required platform document is not seeded
     */
    public function recordRegistrationConsents(User $user, ?string $ipAddress, ?string $userAgent): void;

    /**
     * Active platform registration documents keyed by their LegalDocumentType value, for the
     * register form to surface ("read" dialogs). Missing types are simply absent from the map.
     *
     * @return array<string, LegalDocument>
     */
    public function activeRegistrationDocuments(): array;
}
