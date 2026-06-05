<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('now', '+3 months');
        $endsAt = (clone $startsAt)->modify('+30 minutes');

        return [
            'clinic_id' => Clinic::factory(),
            'patient_id' => Patient::factory(),
            'doctor_id' => Doctor::factory(),
            'case_id' => null,
            'appointment_type_id' => null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => AppointmentStatus::Confirmed,
            'is_walk_in' => false,
            'reminder_24h_sent' => false,
            'reminder_1h_sent' => false,
            'created_by' => null,
        ];
    }

    public function walkIn(): static
    {
        return $this->state(['is_walk_in' => true]);
    }

    public function withStatus(AppointmentStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function past(): static
    {
        return $this->state(function (): array {
            $startsAt = fake()->dateTimeBetween('-3 months', '-1 day');
            $endsAt = (clone $startsAt)->modify('+30 minutes');

            return ['starts_at' => $startsAt, 'ends_at' => $endsAt];
        });
    }

    public function createdBy(User $user): static
    {
        return $this->state(['created_by' => $user->id]);
    }
}
