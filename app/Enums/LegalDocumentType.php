<?php

namespace App\Enums;

enum LegalDocumentType: string
{
    case KvkkConsent = 'kvkk_consent';
    case TermsOfService = 'terms_of_service';
    case PrivacyPolicy = 'privacy_policy';
    case DataProcessingAgreement = 'data_processing_agreement';
    case TreatmentConsent = 'treatment_consent';
    case AnamnezConsent = 'anamnez_consent';
}
