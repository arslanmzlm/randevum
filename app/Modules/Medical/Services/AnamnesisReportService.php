<?php

namespace App\Modules\Medical\Services;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PodiatryAnamnesis;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Assembles the anamnesis (health-intake) PDF. No storage — every call renders a
 * fresh PdfBuilder, mirroring TreatmentReportService's on-demand approach (1.39).
 */
class AnamnesisReportService
{
    /**
     * @var list<string>
     */
    private const SELECT_FIELDS = ['blood_type', 'smoking', 'alcohol', 'diabetes', 'pregnancy'];

    /**
     * @var list<string>
     */
    private const BOOLEAN_FIELDS = ['hypertension', 'cardiovascular', 'blood_thinners', 'diabetic_foot_history'];

    public function build(Patient $patient): PdfBuilder
    {
        $patient->loadMissing(['clinic.city', 'anamnesis']);

        $clinic = $patient->clinic;

        $data = $this->buildViewData($patient, $clinic);

        return Pdf::view('pdf.anamnesis', $data)
            ->format(Format::A4)
            ->name($data['documentNumber'].'.pdf');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewData(Patient $patient, Clinic $clinic): array
    {
        $lang = strtolower(explode('_', $clinic->locale)[0]);
        $documentDate = now()->setTimezone($clinic->timezone);

        /** @var ?PodiatryAnamnesis $anamnesis */
        $anamnesis = $patient->anamnesis;

        return [
            'documentNumber' => sprintf('anamnez-%d-%d', $patient->id, $documentDate->year),
            'clinic' => [
                'name' => $clinic->name,
                'address' => $this->formatClinicAddress($clinic),
                'phone' => $clinic->phone,
                'logoDataUri' => $this->logoDataUri($clinic),
            ],
            'patient' => [
                'fullName' => trim($patient->first_name.' '.$patient->last_name),
            ],
            'documentDate' => $documentDate->locale($lang)->translatedFormat('d F Y'),
            'groups' => $this->buildGroups($anamnesis),
        ];
    }

    /**
     * @return list<array{title: string, fields: list<array{label: string, value: string}>}>
     */
    private function buildGroups(?PodiatryAnamnesis $anamnesis): array
    {
        if ($anamnesis === null) {
            return [];
        }

        $groups = [
            'general' => ['blood_type', 'height_cm', 'weight_kg', 'smoking', 'alcohol'],
            'systemic' => ['diabetes', 'hypertension', 'cardiovascular', 'blood_thinners', 'regular_medications', 'other_chronic'],
            'allergy' => ['allergies'],
            'women' => ['pregnancy'],
            'podiatry' => ['foot_surgery_history', 'diabetic_foot_history', 'current_foot_complaint'],
        ];

        $result = [];

        foreach ($groups as $groupKey => $fields) {
            $renderedFields = [];

            foreach ($fields as $field) {
                $value = $this->formatFieldValue($field, $anamnesis->{$field});

                if ($value === null) {
                    continue;
                }

                $renderedFields[] = [
                    'label' => __('health.fields.'.$field),
                    'value' => $value,
                ];
            }

            if ($renderedFields !== []) {
                $result[] = [
                    'title' => __('health.groups.'.$groupKey),
                    'fields' => $renderedFields,
                ];
            }
        }

        return $result;
    }

    private function formatFieldValue(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (in_array($field, self::BOOLEAN_FIELDS, true)) {
            return $value ? __('health.yes') : __('health.no');
        }

        if (in_array($field, self::SELECT_FIELDS, true)) {
            return __('health.options.'.$field.'.'.$value);
        }

        if ($field === 'height_cm') {
            return $value.' '.__('health.units.cm');
        }

        if ($field === 'weight_kg') {
            return $value.' '.__('health.units.kg');
        }

        return (string) $value;
    }

    private function formatClinicAddress(Clinic $clinic): string
    {
        return implode(', ', array_filter([
            $clinic->address,
            $clinic->district,
            $clinic->city?->name,
        ]));
    }

    /**
     * Reads the logo through its Storage disk directly (never an HTTP fetch): works
     * identically for the local 'public' disk (self-signed DDEV TLS would otherwise
     * break an HTTP round-trip) and for S3, and needs no network call either way.
     * Prefers the 'thumb' conversion (a letterhead needs no more) and falls back to
     * the original until the queued conversion exists. Duplicated from
     * TreatmentReportService (accepted minor duplication — avoids regression risk on 1.39).
     */
    private function logoDataUri(Clinic $clinic): ?string
    {
        $media = $clinic->getFirstMedia('logo');

        if ($media === null) {
            return null;
        }

        $conversion = $media->hasGeneratedConversion('thumb') ? 'thumb' : '';

        $contents = Storage::disk($media->disk)->get($media->getPathRelativeToRoot($conversion));

        if ($contents === null) {
            return null;
        }

        $mimeType = $conversion === 'thumb' ? 'image/webp' : $media->mime_type;

        return 'data:'.$mimeType.';base64,'.base64_encode($contents);
    }
}
