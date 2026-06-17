<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform legal documents — accepted by the clinic owner at signup
    |--------------------------------------------------------------------------
    |
    | Registry of the platform-level legal documents (clinic_id = null) seeded
    | into `legal_documents` and presented for acceptance on the register form.
    | Keyed by LegalDocumentType value so the seeder and resolver line up.
    |
    | Only metadata lives here. Each document's TEXT is a plain file at
    | resources/legal/<type>/<version>.md — kept out of PHP so it diffs cleanly
    | and a lawyer can edit it without touching code. Bumping `version` and adding
    | resources/legal/<type>/<new-version>.md publishes a new immutable version.
    |
    | DRAFT SKELETONS — a KVKK lawyer MUST finalize them before go-live. The Data
    | Processing Agreement is the platform's liability shield: the clinic is the
    | controller (veri sorumlusu) and the platform the processor (veri işleyen);
    | patient-data misuse risk is contractually pushed to the clinic.
    |
    */

    'documents' => [
        'terms_of_service' => [
            'version' => '1.0',
            'title' => 'Kullanım Koşulları',
        ],
        'privacy_policy' => [
            'version' => '1.0',
            'title' => 'Gizlilik Politikası ve Aydınlatma Metni',
        ],
        'data_processing_agreement' => [
            'version' => '1.0',
            'title' => 'Veri İşleme Sözleşmesi',
        ],
    ],

];
