<?php

namespace App\Modules\Medical\Services;

use App\Enums\AnamnesisFieldType;
use App\Models\Anamnesis;
use App\Models\AnamnesisField;
use App\Models\Clinic;
use App\Models\Patient;
use Illuminate\Support\Carbon;
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
    private const BOOLEAN_FIELDS = [
        'hypertension', 'cardiovascular', 'respiratory', 'kidney_liver', 'thyroid',
        'epilepsy', 'blood_thinners', 'bleeding_disorder', 'infectious_disease',
    ];

    /**
     * Fixed core section groups, in display order. `bmi` is derived, never a real column.
     *
     * @var array<string, list<string>>
     */
    private const CORE_GROUPS = [
        'general' => ['blood_type', 'height_cm', 'weight_kg', 'bmi', 'smoking', 'alcohol'],
        'systemic' => [
            'diabetes', 'hypertension', 'cardiovascular', 'respiratory', 'kidney_liver',
            'thyroid', 'epilepsy', 'bleeding_disorder', 'blood_thinners', 'infectious_disease',
            'infectious_disease_note', 'regular_medications', 'other_chronic',
        ],
        'allergy' => ['allergies'],
        'history' => ['surgery_history', 'family_history'],
        'women' => ['pregnancy', 'menstrual_notes'],
    ];

    public function __construct(
        private AnamnesisFieldService $anamnesisFieldService,
    ) {}

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

        /** @var ?Anamnesis $anamnesis */
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
    private function buildGroups(?Anamnesis $anamnesis): array
    {
        if ($anamnesis === null) {
            return [];
        }

        $result = $this->buildCoreGroups($anamnesis);

        return [...$result, ...$this->buildDynamicGroups($anamnesis)];
    }

    /**
     * @return list<array{title: string, fields: list<array{label: string, value: string}>}>
     */
    private function buildCoreGroups(Anamnesis $anamnesis): array
    {
        $result = [];

        foreach (self::CORE_GROUPS as $groupKey => $fields) {
            $renderedFields = [];

            foreach ($fields as $field) {
                $rawValue = $field === 'bmi' ? $anamnesis->bmi() : $anamnesis->{$field};
                $value = $this->formatCoreFieldValue($field, $rawValue);

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

    /**
     * Dynamic (vertical-specific) groups, inactive definitions included, grouped in
     * first-encounter order over the sort-ordered definition list.
     *
     * @return list<array{title: string, fields: list<array{label: string, value: string}>}>
     */
    private function buildDynamicGroups(Anamnesis $anamnesis): array
    {
        $extra = $anamnesis->extra ?? [];

        if ($extra === []) {
            return [];
        }

        $fields = $this->anamnesisFieldService->definitionsForActiveClinic(includeInactive: true);

        /** @var array<string, array{title: string, fields: list<array{label: string, value: string}>}> $groups */
        $groups = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field->key, $extra) || $extra[$field->key] === null) {
                continue;
            }

            $value = $this->formatDynamicFieldValue($field, $extra[$field->key]);

            if ($value === null) {
                continue;
            }

            $groupTitle = __($field->group);

            $groups[$groupTitle] ??= ['title' => $groupTitle, 'fields' => []];
            $groups[$groupTitle]['fields'][] = [
                'label' => __($field->label),
                'value' => $value,
            ];
        }

        return array_values($groups);
    }

    private function formatCoreFieldValue(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (in_array($field, self::BOOLEAN_FIELDS, true)) {
            // A false flag is omitted, same as an unfilled field — nine risk flags always
            // printing "Hayır" would bury the positive findings a clinician needs to see.
            return $value ? __('health.yes') : null;
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

        if ($field === 'bmi') {
            return $value.' '.__('health.units.bmi');
        }

        return (string) $value;
    }

    private function formatDynamicFieldValue(AnamnesisField $field, mixed $value): ?string
    {
        return match ($field->type) {
            // Same rule as the core flags: a false flag is omitted rather than printed as
            // "Hayır", so the positive findings stay visible.
            AnamnesisFieldType::Boolean => $value ? __('health.yes') : null,
            AnamnesisFieldType::Select => $this->optionLabel($field, (string) $value),
            AnamnesisFieldType::Multiselect => is_array($value)
                ? implode(', ', array_filter(array_map(fn ($v) => $this->optionLabel($field, (string) $v), $value)))
                : null,
            AnamnesisFieldType::Date => Carbon::parse($value)->format('d.m.Y'),
            default => (string) $value,
        };
    }

    private function optionLabel(AnamnesisField $field, string $value): ?string
    {
        $options = $field->options ?? [];

        foreach ($options as $option) {
            if ($option['value'] === $value) {
                return __($option['label']);
            }
        }

        return $value;
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
