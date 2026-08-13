<?php

namespace Database\Seeders;

use App\Models\Anamnesis;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientSegment;
use App\Models\Tag;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

/**
 * The patient-facing CRM layer: clinic tags with assignments, saved segments covering every
 * criteria dimension, clinical notes, legacy patients, and filled anamnesis forms — so the
 * patient list columns/filters, the tag chips, the segment picker and the anamnesis section
 * all render with data instead of empty states.
 *
 * Runs AFTER DemoSeeder (needs the clinic + its 100 patients).
 */
class DemoCrmSeeder extends Seeder
{
    public function run(): void
    {
        $clinic = Clinic::where('slug', 'podosen-izmir')->first();

        if (! $clinic) {
            return;
        }

        $patients = Patient::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->orderBy('id')
            ->get();

        if ($patients->count() < 20) {
            return;
        }

        $tags = $this->seedTags($clinic->id);
        $this->assignTags($patients, $tags);
        $this->seedSegments($clinic->id, $tags);
        $this->annotatePatients($patients);
        $this->seedAnamneses($patients);
    }

    /**
     * @return array<string, Tag>
     */
    private function seedTags(int $clinicId): array
    {
        $definitions = [
            'Diyabetik' => '#DC2626',
            'VIP' => '#7C3AED',
            'Kontrol bekliyor' => '#0891B2',
            'Riskli hasta' => '#EA580C',
            'Sigortalı' => '#059669',
        ];

        $tags = [];

        foreach ($definitions as $name => $color) {
            $tags[$name] = Tag::withoutGlobalScopes()->firstOrCreate(
                ['clinic_id' => $clinicId, 'name' => $name],
                ['color' => $color],
            );
        }

        return $tags;
    }

    /**
     * @param  Collection<int, Patient>  $patients
     * @param  array<string, Tag>  $tags
     */
    private function assignTags($patients, array $tags): void
    {
        $tagIds = array_map(fn (Tag $tag): int => $tag->id, array_values($tags));

        // Roughly half the list is tagged, a handful carry three at once so the column's
        // overflow behaviour is visible.
        foreach ($patients as $index => $patient) {
            // Seeders run with no active clinic and ClinicScope is fail-closed; without the
            // opt-out this idempotency guard would always read false.
            if ($patient->tags()->withoutGlobalScopes()->exists()) {
                continue;
            }

            $count = match (true) {
                $index % 9 === 0 => 3,
                $index % 3 === 0 => 2,
                $index % 2 === 0 => 1,
                default => 0,
            };

            if ($count === 0) {
                continue;
            }

            shuffle($tagIds);
            $patient->tags()->syncWithoutDetaching(array_slice($tagIds, 0, $count));
        }
    }

    /**
     * @param  array<string, Tag>  $tags
     */
    private function seedSegments(int $clinicId, array $tags): void
    {
        $segments = [
            'Diyabetik hastalar' => ['tags' => [$tags['Diyabetik']->id]],
            'Kadın hastalar' => ['gender' => 'female'],
            'Devredilen kayıtlar' => ['is_legacy' => true],
            '3 aydır gelmeyenler' => ['last_visit_before' => Carbon::today()->subMonths(3)->toDateString()],
            'VIP + riskli' => ['tags' => [$tags['VIP']->id, $tags['Riskli hasta']->id]],
        ];

        foreach ($segments as $name => $criteria) {
            PatientSegment::withoutGlobalScopes()->firstOrCreate(
                ['clinic_id' => $clinicId, 'name' => $name],
                ['criteria' => $criteria],
            );
        }
    }

    /**
     * @param  Collection<int, Patient>  $patients
     */
    private function annotatePatients($patients): void
    {
        $notes = [
            'Diyabetik, adrenalinli anestezik kullanılmayacak.',
            'Kan sulandırıcı kullanıyor, kanama riski yüksek.',
            'Lateks alerjisi var.',
            'İşitme cihazı kullanıyor, yüksek sesle konuşun.',
            'Randevularına genelde eşi ile geliyor.',
            'Ağrı eşiği düşük, işlem öncesi bilgilendirme gerekiyor.',
        ];

        foreach ($patients as $index => $patient) {
            $changes = [];

            // Every 7th patient carries a clinical note (patient card + list hint).
            if ($patient->notes === null && $index % 7 === 0) {
                $changes['notes'] = $notes[$index % count($notes)];
            }

            // A block of migrated-from-the-old-system records for the legacy filter/segment.
            if (! $patient->is_legacy && $index % 11 === 0) {
                $changes['is_legacy'] = true;
            }

            if ($changes !== []) {
                $patient->forceFill($changes)->save();
            }
        }
    }

    /**
     * @param  Collection<int, Patient>  $patients
     */
    private function seedAnamneses($patients): void
    {
        $profiles = [
            [
                'blood_type' => 'A+', 'height_cm' => 172, 'weight_kg' => 88.5,
                'smoking' => 'regular', 'alcohol' => 'occasional', 'diabetes' => 'type2',
                'hypertension' => true, 'cardiovascular' => false, 'respiratory' => false,
                'kidney_liver' => false, 'thyroid' => false, 'epilepsy' => false,
                'blood_thinners' => true, 'bleeding_disorder' => false,
                'infectious_disease' => false, 'infectious_disease_note' => null,
                'regular_medications' => 'Metformin 1000 mg, Ramipril 5 mg',
                'other_chronic' => null, 'allergies' => 'Penisilin',
                'surgery_history' => null, 'family_history' => 'Baba: tip 2 diyabet',
                'pregnancy' => null, 'menstrual_notes' => null,
                'extra' => [
                    'foot_surgery_history' => 'Sol ayak başparmak tırnak matriksektomisi (2021)',
                    'diabetic_foot_history' => true,
                    'current_foot_complaint' => 'Sağ topukta çatlak ve ağrı, yürürken artıyor.',
                ],
            ],
            [
                'blood_type' => '0+', 'height_cm' => 160, 'weight_kg' => 62.0,
                'smoking' => 'none', 'alcohol' => 'none', 'diabetes' => null,
                'hypertension' => false, 'cardiovascular' => false, 'respiratory' => false,
                'kidney_liver' => false, 'thyroid' => true, 'epilepsy' => false,
                'blood_thinners' => false, 'bleeding_disorder' => false,
                'infectious_disease' => false, 'infectious_disease_note' => null,
                'regular_medications' => null, 'other_chronic' => 'Hipotiroidi',
                'allergies' => null, 'surgery_history' => null, 'family_history' => null,
                'pregnancy' => 'pregnant', 'menstrual_notes' => null,
                'extra' => [
                    'foot_surgery_history' => null,
                    'diabetic_foot_history' => false,
                    'current_foot_complaint' => 'İki ayakta da batık tırnak şikayeti.',
                ],
            ],
            [
                'blood_type' => 'B-', 'height_cm' => 181, 'weight_kg' => 95.2,
                'smoking' => 'none', 'alcohol' => 'regular', 'diabetes' => 'type1',
                'hypertension' => true, 'cardiovascular' => true, 'respiratory' => true,
                'kidney_liver' => false, 'thyroid' => false, 'epilepsy' => false,
                'blood_thinners' => true, 'bleeding_disorder' => true,
                'infectious_disease' => false, 'infectious_disease_note' => null,
                'regular_medications' => 'İnsulin glarjin, Asetilsalisilik asit 100 mg',
                'other_chronic' => 'KOAH', 'allergies' => 'Lateks, iyot',
                'surgery_history' => 'Sağ diz menisküs ameliyatı (2015)',
                'family_history' => 'Anne: hipertansiyon, kardiyovasküler hastalık',
                'pregnancy' => null, 'menstrual_notes' => null,
                'extra' => [
                    'foot_surgery_history' => 'Sağ ayak 2. parmak amputasyonu (2019)',
                    'diabetic_foot_history' => true,
                    'current_foot_complaint' => 'Sol ayak tabanında iyileşmeyen ülser, 3 haftadır mevcut.',
                ],
            ],
            [
                'blood_type' => 'AB+', 'height_cm' => 168, 'weight_kg' => 70.0,
                'smoking' => 'none', 'alcohol' => 'occasional', 'diabetes' => null,
                'hypertension' => false, 'cardiovascular' => false, 'respiratory' => false,
                'kidney_liver' => false, 'thyroid' => false, 'epilepsy' => false,
                'blood_thinners' => false, 'bleeding_disorder' => false,
                'infectious_disease' => false, 'infectious_disease_note' => null,
                'regular_medications' => null, 'other_chronic' => null, 'allergies' => null,
                'surgery_history' => null, 'family_history' => null,
                'pregnancy' => null, 'menstrual_notes' => null,
                'extra' => [
                    'foot_surgery_history' => null,
                    'diabetic_foot_history' => false,
                    'current_foot_complaint' => 'Nasır şikayeti, ayakkabı vurması.',
                ],
            ],
        ];

        // Every 5th patient has a completed form; the rest stay empty so the "henüz doldurulmadı"
        // state is reviewable too.
        foreach ($patients as $index => $patient) {
            // Same as the tag guard: without the opt-out a re-run would add a second
            // anamnesis row per patient (no clinic context → fail-closed read).
            if ($index % 5 !== 0 || $patient->anamnesis()->withoutGlobalScopes()->exists()) {
                continue;
            }

            Anamnesis::withoutGlobalScopes()->create([
                'clinic_id' => $patient->clinic_id,
                'patient_id' => $patient->id,
                ...$profiles[$index % count($profiles)],
            ]);
        }
    }
}
