<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Enums\CaseStatus;
use App\Enums\FollowUpStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\CaseRecord;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\FollowUp;
use App\Models\FollowUpType;
use App\Models\Patient;
use App\Models\Service;
use App\Models\StatusLog;
use App\Models\Treatment;
use App\Models\TreatmentServiceLine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Demo cases + completed treatments for the demo clinic, covering every case-management
 * scenario reviewable in the UI: all four statuses, legal-transition buttons per state,
 * a reopened case, the 48h title edit window (editable + locked), follow-up with/without
 * a closed case, status_log history chains, per-doctor own/all scoping, a patient with
 * open+closed cases, and ungrouped completed treatments for the linking flows (same
 * doctor as an open case, a second doctor on the same patient, and case-less patients).
 *
 * Runs AFTER DemoSeeder + vertical service seeders (needs doctors, patients, services).
 */
class DemoCasesSeeder extends Seeder
{
    private Clinic $clinic;

    private User $owner;

    /** @var list<int> */
    private array $serviceIds;

    /** @var list<int> */
    private array $followUpTypeIds;

    public function run(): void
    {
        $clinic = Clinic::where('slug', 'podosen-izmir')->first();
        $owner = User::where('email', 'owner@podosen.test')->first();

        if (! $clinic || ! $owner) {
            return;
        }

        $this->clinic = $clinic;
        $this->owner = $owner;
        $this->serviceIds = Service::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('is_active', true)
            ->pluck('id')
            ->all();
        // Populated by FollowUpTypeSeeder (called earlier in the DatabaseSeeder chain).
        $this->followUpTypeIds = FollowUpType::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->pluck('id')
            ->all();

        // The case set is built once; drafts are day-relative and topped up on every re-seed, so
        // "today" always has a treatment in progress however old the database is.
        if (CaseRecord::withoutGlobalScopes()->where('clinic_id', $clinic->id)->count() >= 10) {
            $this->makeTodaysDrafts();

            return;
        }

        $doctors = Doctor::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('is_active', true)
            ->with('user')
            ->orderBy('id')
            ->take(3)
            ->get();

        $patients = Patient::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->orderBy('id')
            ->take(30)
            ->get();

        // Five patients per doctor in the loop below, plus seven fixed ones after it —
        // derived rather than hardcoded so adding a scenario can't outgrow the guard.
        $patientsNeeded = $doctors->count() * 5 + 7;

        if ($doctors->isEmpty() || $patients->count() < $patientsNeeded || $this->serviceIds === []) {
            return;
        }

        $titles = [
            'Tırnak Batması Tedavisi',
            'Diyabetik Ayak Takibi',
            'Nasır ve Kallus Bakımı',
            'Mantar Enfeksiyonu Takibi',
            'Topuk Çatlağı Tedavisi',
            'Ortonix Tel Uygulaması',
        ];

        $p = 0; // next unused patient index

        foreach ($doctors as $d => $doctor) {
            // 1) Open, <48h old → title still editable; 1 treatment + notes.
            // Its patient also gets the old closed case below → open/closed split on patient page.
            $freshPatient = $patients[$p++];
            $fresh = $this->makeCase($doctor, $freshPatient, $titles[0], CaseStatus::Open, daysAgo: 1, notes: 'İlk seansta sağ ayak başparmak tırnağı kaldırıldı, pansuman yapıldı.');
            $this->makeTreatment($doctor, $freshPatient, daysAgo: 1, case: $fresh);
            // ungrouped completed treatments, same doctor → linkable from the case Show dialog
            $this->makeTreatment($doctor, $freshPatient, daysAgo: 10);
            $this->makeTreatment($doctor, $freshPatient, daysAgo: 25);

            // 2) Open, 3 weeks old → title locked; multiple treatments.
            $patient = $patients[$p++];
            $case = $this->makeCase($doctor, $patient, $titles[1], CaseStatus::Open, daysAgo: 21, notes: 'HbA1c yüksek; haftalık debridman ve yara takibi sürüyor.');
            foreach ([20, 13, 6] as $ago) {
                $this->makeTreatment($doctor, $patient, daysAgo: $ago, case: $case);
            }

            // 3) Reopened: closed → open again; history shows the full chain.
            $patient = $patients[$p++];
            $case = $this->makeCase($doctor, $patient, $titles[2], CaseStatus::Open, daysAgo: 60);
            $this->log($case, CaseStatus::Open, CaseStatus::Closed, daysAgo: 30, by: $doctor->user);
            $this->log($case, CaseStatus::Closed, CaseStatus::Open, daysAgo: 4, by: $this->owner);
            $this->makeTreatment($doctor, $patient, daysAgo: 45, case: $case);
            $this->makeTreatment($doctor, $patient, daysAgo: 3, case: $case);

            // 4) Suspended (hasta ara verdi) → only Open/Closed transitions offered.
            $patient = $patients[$p++];
            $case = $this->makeCase($doctor, $patient, $titles[3], CaseStatus::Suspended, daysAgo: 35, notes: 'Hasta şehir dışında, dönüşte devam edilecek.');
            $case->update(['suspended_at' => $this->dayUtc(7)]);
            $this->log($case, CaseStatus::Open, CaseStatus::Suspended, daysAgo: 7, by: $doctor->user);
            $this->makeTreatment($doctor, $patient, daysAgo: 30, case: $case);

            // 5) FollowUp due in the past → shows in the dashboard "Bugün aranacaklar" call list
            //    flagged overdue. Staggered per doctor so the oldest-due-first ordering is visible.
            $patient = $patients[$p++];
            $case = $this->makeCase($doctor, $patient, $titles[4], CaseStatus::FollowUp, daysAgo: 50);
            $this->makeFollowUp(
                $patient, $case,
                Carbon::today($this->clinic->timezone)->subDays(2 + $d * 3)->toDateString(),
                'Kontrol randevusu için aranacak; topuk bölgesi fotoğrafla karşılaştırılacak.',
                $doctor->user,
            );
            $this->log($case, CaseStatus::Open, CaseStatus::FollowUp, daysAgo: 8, by: $doctor->user);
            $this->makeTreatment($doctor, $patient, daysAgo: 40, case: $case);
            $this->makeTreatment($doctor, $patient, daysAgo: 15, case: $case);

            // 6) Closed, on the fresh case's patient → patient page shows open + closed together.
            $case = $this->makeCase($doctor, $freshPatient, $titles[5], CaseStatus::Closed, daysAgo: 90, notes: 'Tedavi tamamlandı, tırnak sağlıklı uzuyor.');
            $case->update(['closed_at' => $this->dayUtc(14)]);
            $this->log($case, CaseStatus::Open, CaseStatus::Closed, daysAgo: 14, by: $doctor->user);
            $this->makeTreatment($doctor, $freshPatient, daysAgo: 80, case: $case);
            $this->makeTreatment($doctor, $freshPatient, daysAgo: 50, case: $case);
        }

        $firstDoctor = $doctors[0];
        $secondDoctor = $doctors->count() > 1 ? $doctors[1] : $doctors[0];

        // Closed case that still carries a follow-up reminder (allowed by the domain rules).
        $patient = $patients[$p++];
        $case = $this->makeCase($firstDoctor, $patient, 'Tırnak Protezi Uygulaması', CaseStatus::Closed, daysAgo: 70);
        $case->update(['closed_at' => $this->dayUtc(10)]);
        $this->makeFollowUp(
            $patient, $case,
            Carbon::today($this->clinic->timezone)->addDays(30)->toDateString(),
            'Protez kontrolü için 1 ay sonra hatırlat.',
            $firstDoctor->user,
        );
        $this->log($case, CaseStatus::Open, CaseStatus::Closed, daysAgo: 10, by: $firstDoctor->user);
        $this->makeTreatment($firstDoctor, $patient, daysAgo: 65, case: $case);

        // FollowUp due TODAY → appears in the dashboard call list without the overdue flag,
        // so the widget shows both an overdue and a same-day row.
        $patient = $patients[$p++];
        $case = $this->makeCase($secondDoctor, $patient, 'Nasır Kontrolü', CaseStatus::FollowUp, daysAgo: 18);
        $this->makeFollowUp(
            $patient, $case,
            Carbon::today($this->clinic->timezone)->toDateString(),
            'Bugün aranacak; nasır tekrarladı mı sorulacak.',
            $secondDoctor->user,
        );
        $this->log($case, CaseStatus::Open, CaseStatus::FollowUp, daysAgo: 5, by: $secondDoctor->user);
        $this->makeTreatment($secondDoctor, $patient, daysAgo: 12, case: $case);

        // Completed follow-up with a result note → the case-panel history list has content.
        $patient = $patients[$p++];
        $case = $this->makeCase($firstDoctor, $patient, 'Mantar Enfeksiyonu Kontrolü', CaseStatus::Open, daysAgo: 25);
        $this->makeFollowUp(
            $patient, $case,
            Carbon::today($this->clinic->timezone)->subDays(5)->toDateString(),
            'Topikal tedavi sonrası kontrol araması.',
            $firstDoctor->user,
            status: FollowUpStatus::Done,
            resultNote: 'Hasta ulaşıldı, şikayet gerilemiş; 2 hafta sonra tekrar kontrol önerildi.',
            completedBy: $this->owner,
            completedAt: $this->dayUtc(3),
        );
        $this->makeTreatment($firstDoctor, $patient, daysAgo: 25, case: $case);

        // Patient-only follow-up (no case) → demoes the case-less manual-creation path.
        $patient = $patients[$p++];
        $this->makeFollowUp(
            $patient, null,
            Carbon::today($this->clinic->timezone)->addDays(3)->toDateString(),
            'Ödeme hatırlatması için aranacak.',
            $this->owner,
        );

        // Open case with NO treatments yet — empty treatments list + linkable ungrouped ones.
        $patient = $patients[$p++];
        $this->makeCase($firstDoctor, $patient, 'Ayak Tabanı Ağrısı Değerlendirmesi', CaseStatus::Open, daysAgo: 2);
        $this->makeTreatment($firstDoctor, $patient, daysAgo: 12);

        // Case-less patient with ungrouped treatments from TWO doctors → the patient-page
        // "create case" flow must split the selection by doctor.
        $patient = $patients[$p++];
        $this->makeTreatment($firstDoctor, $patient, daysAgo: 9);
        $this->makeTreatment($firstDoctor, $patient, daysAgo: 18);
        $this->makeTreatment($secondDoctor, $patient, daysAgo: 5);

        // Case-less patient with a single doctor's ungrouped treatments.
        $patient = $patients[$p++];
        $this->makeTreatment($secondDoctor, $patient, daysAgo: 6);
        $this->makeTreatment($secondDoctor, $patient, daysAgo: 20);

        $this->makeTodaysDrafts();
    }

    /**
     * Draft treatments on today's bookings — the Process ("İşle") screen's working state, which
     * the completed set above never shows. One is still empty (fresh form), one already carries a
     * service line (part-filled form with a running total).
     */
    private function makeTodaysDrafts(): void
    {
        $today = Carbon::today($this->clinic->timezone);

        $draftExists = Treatment::withoutGlobalScopes()
            ->where('clinic_id', $this->clinic->id)
            ->where('status', TreatmentStatus::Draft)
            ->whereHas('appointment', fn ($query) => $query->withoutGlobalScopes()->whereBetween(
                'starts_at',
                [$today->copy()->startOfDay()->utc(), $today->copy()->endOfDay()->utc()],
            ))
            ->exists();

        if ($draftExists) {
            return;
        }

        $appointments = Appointment::withoutGlobalScopes()
            ->where('clinic_id', $this->clinic->id)
            ->whereBetween('starts_at', [$today->copy()->startOfDay()->utc(), $today->copy()->endOfDay()->utc()])
            ->whereDoesntHave('treatment')
            ->orderBy('starts_at')
            ->take(2)
            ->get();

        foreach ($appointments as $index => $appointment) {
            $treatment = Treatment::create([
                'clinic_id' => $this->clinic->id,
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'doctor_id' => $appointment->doctor_id,
                ...$this->clinicalNotes($index),
                'status' => TreatmentStatus::Draft,
                'created_by' => $this->owner->id,
            ]);

            if ($index === 0 || $this->serviceIds === []) {
                continue; // leave the first one empty
            }

            $service = Service::withoutGlobalScopes()->find($this->serviceIds[0]);

            TreatmentServiceLine::create([
                'treatment_id' => $treatment->id,
                'service_id' => $service->id,
                'quantity' => 1,
                'unit_price' => $service->price,
                'discount_amount' => 0,
                'subtotal' => $service->price,
                'sort_order' => 0,
            ]);

            $treatment->update([
                'subtotal_amount' => $service->price,
                'total_amount' => $service->price,
            ]);
        }
    }

    /**
     * A plausible complaint / diagnosis / process trio. The case page shows these inline, so
     * leaving them empty would make that screen look unimplemented.
     *
     * @return array<string, string>
     */
    private function clinicalNotes(int $index): array
    {
        $notes = [
            [
                'complaint' => 'Sağ ayak başparmak kenarında ağrı ve kızarıklık.',
                'diagnosis' => 'Onikokriptoz (batık tırnak), evre 2.',
                'treatment_process' => 'Kenar rezeksiyonu yapıldı, antiseptik pansuman uygulandı. Ortonixi teli önerildi.',
            ],
            [
                'complaint' => 'Topuk bölgesinde çatlak ve yürürken batma hissi.',
                'diagnosis' => 'Hiperkeratoz ve fissür.',
                'treatment_process' => 'Kallus debridmanı yapıldı, nemlendirici bakım ve günlük pansuman önerildi.',
            ],
            [
                'complaint' => 'Tırnaklarda sararma ve kalınlaşma, 6 aydır sürüyor.',
                'diagnosis' => 'Onikomikoz şüphesi; örnek alındı.',
                'treatment_process' => 'Tırnak inceltme uygulandı, topikal antifungal başlandı. 4 hafta sonra kontrol.',
            ],
            [
                'complaint' => 'Ayak tabanında basınç noktasında ağrı.',
                'diagnosis' => 'Plantar kallus, biyomekanik yüklenme.',
                'treatment_process' => 'Kallus temizliği sonrası kişiye özel tabanlık ölçüsü alındı.',
            ],
            [
                'complaint' => 'Diyabetik hasta, ayak kontrolü için başvurdu.',
                'diagnosis' => 'Diyabetik ayak riski düşük-orta; cilt bütünlüğü korunmuş.',
                'treatment_process' => 'Koruyucu ayak bakımı yapıldı, günlük kontrol ve uygun ayakkabı önerildi.',
            ],
        ];

        return $notes[$index % count($notes)];
    }

    private function makeCase(Doctor $doctor, Patient $patient, string $title, CaseStatus $status, int $daysAgo, ?string $notes = null): CaseRecord
    {
        $case = CaseRecord::create([
            'clinic_id' => $this->clinic->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'vertical_id' => $this->clinic->vertical_id,
            'title' => $title,
            'status' => $status,
            'notes' => $notes,
            'opened_at' => $this->dayUtc($daysAgo),
        ]);

        $this->log($case, null, CaseStatus::Open, $daysAgo, by: $doctor->user);

        return $case;
    }

    /**
     * A completed past appointment + treatment with 1–2 snapshot-priced service lines.
     * Linked to a case when given (also syncing appointments.case_id, like the app does).
     */
    private function makeTreatment(Doctor $doctor, Patient $patient, int $daysAgo, ?CaseRecord $case = null): Treatment
    {
        $startsAt = $this->dayUtc($daysAgo, hour: fake()->numberBetween(9, 16), minute: fake()->randomElement([0, 30]));
        $endsAt = $startsAt->copy()->addMinutes(30);

        $appointment = Appointment::create([
            'clinic_id' => $this->clinic->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'case_id' => $case?->id,
            'service_id' => fake()->randomElement($this->serviceIds),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => AppointmentStatus::Completed,
            'created_by' => $this->owner->id,
        ]);

        $treatment = Treatment::create([
            'clinic_id' => $this->clinic->id,
            'appointment_id' => $appointment->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'case_id' => $case?->id,
            ...$this->clinicalNotes($patient->id + $doctor->id),
            'status' => TreatmentStatus::Completed,
            'completed_at' => $endsAt,
            'created_by' => $doctor->user_id,
        ]);

        $subtotal = 0.0;

        foreach (fake()->randomElements($this->serviceIds, fake()->numberBetween(1, 2)) as $order => $serviceId) {
            $service = Service::withoutGlobalScopes()->find($serviceId);
            $quantity = fake()->numberBetween(1, 2);
            $discount = fake()->boolean(20) ? 50.0 : 0.0;
            $lineSubtotal = max(0, $quantity * (float) $service->price - $discount);

            TreatmentServiceLine::create([
                'treatment_id' => $treatment->id,
                'service_id' => $serviceId,
                'quantity' => $quantity,
                'unit_price' => $service->price,
                'discount_amount' => $discount,
                'subtotal' => $lineSubtotal,
                'sort_order' => $order,
            ]);

            $subtotal += $lineSubtotal;
        }

        $treatment->update([
            'subtotal_amount' => $subtotal,
            'discount_amount' => 0,
            'total_amount' => $subtotal,
        ]);

        return $treatment;
    }

    private function log(CaseRecord $case, ?CaseStatus $from, CaseStatus $to, int $daysAgo, User $by): void
    {
        StatusLog::create([
            'clinic_id' => $this->clinic->id,
            'loggable_type' => $case->getMorphClass(),
            'loggable_id' => $case->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'transitioned_at' => $this->dayUtc($daysAgo),
            'by_user_id' => $by->id,
        ]);
    }

    /**
     * A follow-up row (replacing the old cases.follow_up_date/note columns), with its
     * null → open status_log and, when $status !== Open, the open → $status transition too.
     */
    private function makeFollowUp(
        Patient $patient,
        ?CaseRecord $case,
        string $dueDate,
        string $note,
        User $by,
        FollowUpStatus $status = FollowUpStatus::Open,
        ?string $resultNote = null,
        ?User $completedBy = null,
        ?Carbon $completedAt = null,
    ): FollowUp {
        $followUp = FollowUp::create([
            'clinic_id' => $this->clinic->id,
            'patient_id' => $patient->id,
            'case_id' => $case?->id,
            'follow_up_type_id' => $this->followUpTypeIds !== [] ? fake()->randomElement($this->followUpTypeIds) : null,
            'due_date' => $dueDate,
            'note' => $note,
            'status' => $status->value,
            'created_by_user_id' => $by->id,
            'completed_by_user_id' => $completedBy?->id,
            'completed_at' => $completedAt,
            'result_note' => $resultNote,
        ]);

        StatusLog::create([
            'clinic_id' => $this->clinic->id,
            'loggable_type' => $followUp->getMorphClass(),
            'loggable_id' => $followUp->id,
            'from_status' => null,
            'to_status' => FollowUpStatus::Open->value,
            'transitioned_at' => $followUp->created_at,
            'by_user_id' => $by->id,
        ]);

        if ($status !== FollowUpStatus::Open) {
            StatusLog::create([
                'clinic_id' => $this->clinic->id,
                'loggable_type' => $followUp->getMorphClass(),
                'loggable_id' => $followUp->id,
                'from_status' => FollowUpStatus::Open->value,
                'to_status' => $status->value,
                'transitioned_at' => $completedAt ?? now(),
                'by_user_id' => ($completedBy ?? $by)->id,
            ]);
        }

        return $followUp;
    }

    /** Clinic-local moment N days back, shifted off weekends, stored as UTC. */
    private function dayUtc(int $daysAgo, int $hour = 10, int $minute = 0): Carbon
    {
        $day = Carbon::today($this->clinic->timezone)->subDays($daysAgo);

        while ($day->isWeekend()) {
            $day->subDay();
        }

        return $day->setTime($hour, $minute)->utc();
    }
}
