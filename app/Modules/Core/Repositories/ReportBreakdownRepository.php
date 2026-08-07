<?php

namespace App\Modules\Core\Repositories;

use App\Enums\AppointmentStatus;
use App\Enums\TransactionStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Doctor;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\Treatment;
use App\Models\User;
use App\Support\ClinicContext;
use Carbon\CarbonInterface;

/**
 * Cross-module aggregation queries for the report breakdown tabs. Lives in Core (the
 * shared kernel) because a single breakdown spans Billing (transactions/expenses),
 * Medical (treatments/line items) and Scheduling (appointments) tables — a Billing- or
 * Medical-owned repository joining across those tables would violate the module
 * boundary. Every query is a grouped sum/count, DB-agnostic (sqlite tests + pgsql).
 *
 * ClinicScope (via BelongsToClinic) filters the base-model table of each query
 * automatically. For joined tables that themselves carry clinic_id, an explicit
 * `where('<table>.clinic_id', ...)` is added defensively — a join could otherwise read
 * across clinics if the FK ever pointed at a stale/foreign row.
 */
class ReportBreakdownRepository
{
    public function __construct(
        private ClinicContext $clinicContext,
    ) {}

    /**
     * Collected (non-pending) amount per doctor, from transactions bound to a
     * treatment. treatment_id NULL rows (manual income) drop out via the inner join.
     *
     * @return array<int, string> doctor_id => amount (2-dp decimal string)
     */
    public function collectedByDoctor(?CarbonInterface $startUtc, ?CarbonInterface $endUtc): array
    {
        $rows = Transaction::query()
            ->join('treatments', 'treatments.id', '=', 'transactions.treatment_id')
            ->where('treatments.clinic_id', $this->clinicContext->id())
            ->whereNot('transactions.status', TransactionStatus::Pending)
            ->when($startUtc, fn ($query) => $query->where('transactions.paid_at', '>=', $startUtc))
            ->when($endUtc, fn ($query) => $query->where('transactions.paid_at', '<=', $endUtc))
            ->groupBy('treatments.doctor_id')
            ->selectRaw('treatments.doctor_id as doctor_id, sum(transactions.amount) as total')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->doctor_id] = bcadd('0', (string) $row->total, 2);
        }

        return $result;
    }

    /**
     * Completed-treatment count per doctor, windowed on completed_at.
     *
     * @return array<int, int> doctor_id => count
     */
    public function completedTreatmentCountByDoctor(?CarbonInterface $startUtc, ?CarbonInterface $endUtc): array
    {
        $rows = Treatment::query()
            ->where('status', TreatmentStatus::Completed)
            ->when($startUtc, fn ($query) => $query->where('completed_at', '>=', $startUtc))
            ->when($endUtc, fn ($query) => $query->where('completed_at', '<=', $endUtc))
            ->groupBy('doctor_id')
            ->selectRaw('doctor_id, count(*) as total')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->doctor_id] = (int) $row->total;
        }

        return $result;
    }

    /**
     * Appointment total/cancelled/no-show counts per doctor, windowed on starts_at.
     * One grouped (doctor_id, status) query, folded in PHP.
     *
     * @return array<int, array{total: int, cancelled: int, no_show: int}>
     */
    public function appointmentCountsByDoctor(?CarbonInterface $startUtc, ?CarbonInterface $endUtc): array
    {
        $rows = Appointment::query()
            ->when($startUtc, fn ($query) => $query->where('starts_at', '>=', $startUtc))
            ->when($endUtc, fn ($query) => $query->where('starts_at', '<=', $endUtc))
            ->groupBy('doctor_id', 'status')
            ->selectRaw('doctor_id, status, count(*) as total')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $doctorId = (int) $row->doctor_id;
            $result[$doctorId] ??= ['total' => 0, 'cancelled' => 0, 'no_show' => 0];
            $count = (int) $row->total;

            $result[$doctorId]['total'] += $count;

            // Appointment::status is cast to AppointmentStatus, so a raw grouped row still
            // hydrates it as the enum instance (not its scalar value) — compare enum-to-enum.
            if ($row->status === AppointmentStatus::Cancelled) {
                $result[$doctorId]['cancelled'] += $count;
            } elseif ($row->status === AppointmentStatus::NoShow) {
                $result[$doctorId]['no_show'] += $count;
            }
        }

        return $result;
    }

    /**
     * Sold quantity/amount per service, from completed treatments' service lines
     * (windowed on the treatment's completed_at). treatment_services carries no
     * clinic_id of its own — it is scoped transitively through treatments.
     *
     * @return array<int, array{quantity: int, total: string}>
     */
    public function serviceLineTotals(?CarbonInterface $startUtc, ?CarbonInterface $endUtc): array
    {
        $rows = Treatment::query()
            ->join('treatment_services', 'treatment_services.treatment_id', '=', 'treatments.id')
            ->where('treatments.status', TreatmentStatus::Completed)
            ->when($startUtc, fn ($query) => $query->where('treatments.completed_at', '>=', $startUtc))
            ->when($endUtc, fn ($query) => $query->where('treatments.completed_at', '<=', $endUtc))
            ->groupBy('treatment_services.service_id')
            ->selectRaw('treatment_services.service_id as service_id, sum(treatment_services.quantity) as quantity, sum(treatment_services.subtotal) as total')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->service_id] = [
                'quantity' => (int) $row->quantity,
                'total' => bcadd('0', (string) $row->total, 2),
            ];
        }

        return $result;
    }

    /**
     * Sold quantity/amount per product, mirrors serviceLineTotals() on treatment_products.
     *
     * @return array<int, array{quantity: int, total: string}>
     */
    public function productLineTotals(?CarbonInterface $startUtc, ?CarbonInterface $endUtc): array
    {
        $rows = Treatment::query()
            ->join('treatment_products', 'treatment_products.treatment_id', '=', 'treatments.id')
            ->where('treatments.status', TreatmentStatus::Completed)
            ->when($startUtc, fn ($query) => $query->where('treatments.completed_at', '>=', $startUtc))
            ->when($endUtc, fn ($query) => $query->where('treatments.completed_at', '<=', $endUtc))
            ->groupBy('treatment_products.product_id')
            ->selectRaw('treatment_products.product_id as product_id, sum(treatment_products.quantity) as quantity, sum(treatment_products.subtotal) as total')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->product_id] = [
                'quantity' => (int) $row->quantity,
                'total' => bcadd('0', (string) $row->total, 2),
            ];
        }

        return $result;
    }

    /**
     * Collected (non-pending) amount per appointment type, from transactions bound to
     * a treatment bound to an appointment. A NULL appointment_type_id groups under the
     * '' key ("Belirtilmemiş" — resolved by the service layer).
     *
     * @return array<string, string> appointment_type_id (or '') => amount (2-dp decimal string)
     */
    public function collectedByAppointmentType(?CarbonInterface $startUtc, ?CarbonInterface $endUtc): array
    {
        $clinicId = $this->clinicContext->id();

        $rows = Transaction::query()
            ->join('treatments', 'treatments.id', '=', 'transactions.treatment_id')
            ->join('appointments', 'appointments.id', '=', 'treatments.appointment_id')
            ->where('treatments.clinic_id', $clinicId)
            ->where('appointments.clinic_id', $clinicId)
            ->whereNull('appointments.deleted_at')
            ->whereNot('transactions.status', TransactionStatus::Pending)
            ->when($startUtc, fn ($query) => $query->where('transactions.paid_at', '>=', $startUtc))
            ->when($endUtc, fn ($query) => $query->where('transactions.paid_at', '<=', $endUtc))
            ->groupBy('appointments.appointment_type_id')
            ->selectRaw('appointments.appointment_type_id as appointment_type_id, sum(transactions.amount) as total')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $key = $row->appointment_type_id === null ? '' : (string) $row->appointment_type_id;
            $result[$key] = bcadd('0', (string) $row->total, 2);
        }

        return $result;
    }

    /**
     * Appointment count per appointment type, windowed on starts_at. A NULL
     * appointment_type_id groups under the '' key, mirroring collectedByAppointmentType().
     *
     * @return array<string, int>
     */
    public function appointmentCountsByType(?CarbonInterface $startUtc, ?CarbonInterface $endUtc): array
    {
        $rows = Appointment::query()
            ->when($startUtc, fn ($query) => $query->where('starts_at', '>=', $startUtc))
            ->when($endUtc, fn ($query) => $query->where('starts_at', '<=', $endUtc))
            ->groupBy('appointment_type_id')
            ->selectRaw('appointment_type_id, count(*) as total')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $key = $row->appointment_type_id === null ? '' : (string) $row->appointment_type_id;
            $result[$key] = (int) $row->total;
        }

        return $result;
    }

    /**
     * Expense count/total per creating user, windowed on expense_date (plain date
     * column — whereDate, not a bare string comparison; see ExpenseRepository).
     * A NULL created_by groups under the '' key.
     *
     * @return array<string, array{count: int, total: string}>
     */
    public function expenseTotalsByOwner(?string $startDate, ?string $endDate): array
    {
        $rows = Expense::query()
            ->when($startDate, fn ($query) => $query->whereDate('expense_date', '>=', $startDate))
            ->when($endDate, fn ($query) => $query->whereDate('expense_date', '<=', $endDate))
            ->groupBy('created_by')
            ->selectRaw('created_by, count(*) as count, sum(amount) as total')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $key = $row->created_by === null ? '' : (string) $row->created_by;
            $result[$key] = [
                'count' => (int) $row->count,
                'total' => bcadd('0', (string) $row->total, 2),
            ];
        }

        return $result;
    }

    /**
     * Doctor display names for the given ids, including soft-deleted doctors — a past
     * report period must still show a doctor who has since left the clinic.
     *
     * @param  array<int, int>  $ids
     * @return array<int, string> doctor_id => display name
     */
    public function doctorLabels(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Doctor::withTrashed()->with('user')->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (Doctor $doctor): array => [$doctor->id => $doctor->display_name])
            ->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, string> service_id => name
     */
    public function serviceLabels(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Service::withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, string> product_id => name
     */
    public function productLabels(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Product::withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, string> appointment_type_id => name
     */
    public function appointmentTypeLabels(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return AppointmentType::withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * Users are not clinic-owned (no BelongsToClinic scope), so this only ever resolves
     * the exact ids handed in — no cross-clinic exposure risk since those ids come
     * from an already clinic-scoped aggregation (expense created_by).
     *
     * @param  array<int, int>  $ids
     * @return array<int, string> user_id => full name
     */
    public function userLabels(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return User::whereIn('id', $ids)->get()
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->name])
            ->all();
    }
}
