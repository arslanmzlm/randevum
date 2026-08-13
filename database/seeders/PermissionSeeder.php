<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Permission => the baseline roles that hold it. Only the abilities the app
     * actually enforces today; this grows feature by feature, not as a full
     * up-front matrix (permission-management UI is Faz 3).
     *
     * Only ever touches GLOBAL role rows (clinic_id null) — a clinic's copy-on-write
     * role customization (RoleCustomizationService) is never synced here, so re-seeding
     * can never overwrite a clinic's customization.
     *
     * @var array<string, list<string>>
     */
    private const PERMISSIONS = [
        'clinic.update' => ['owner', 'manager'],
        // Multi-branch (Dalga 9). Switching between the tenant's branches is owner +
        // manager, matching every other clinic-scoped edit ability; opening a new
        // branch is owner-only.
        'clinics.create' => ['owner'],
        'doctors.viewAny' => ['owner', 'manager', 'doctor', 'receptionist', 'assistant'],
        'doctors.create' => ['owner', 'manager'],
        'doctors.update' => ['owner', 'manager'],
        'doctors.delete' => ['owner', 'manager'],
        // Self-create a doctor profile — owner-only by design; a manager who practises
        // gets a profile created for them like any other doctor.
        'doctors.createOwn' => ['owner'],
        'doctors.offboard' => ['owner', 'manager'],
        'services.viewAny' => ['owner', 'manager', 'doctor'],
        'services.create' => ['owner', 'manager'],
        'services.update' => ['owner', 'manager'],
        'services.delete' => ['owner', 'manager'],
        'products.viewAny' => ['owner', 'manager', 'doctor'],
        'products.create' => ['owner', 'manager'],
        'products.update' => ['owner', 'manager'],
        'products.delete' => ['owner', 'manager'],
        'products.manageStock' => ['owner', 'manager'],
        'patients.viewAny' => ['owner', 'manager', 'doctor', 'receptionist', 'assistant'],
        'patients.view' => ['owner', 'manager', 'doctor', 'receptionist', 'assistant'],
        'patients.create' => ['owner', 'manager', 'doctor', 'receptionist'],
        'patients.update' => ['owner', 'manager', 'doctor', 'receptionist'],
        'patients.delete' => ['owner', 'manager', 'doctor', 'receptionist'],
        'patients.note.update' => ['owner', 'manager', 'doctor', 'receptionist'],
        // Anamnesis (health-intake) fill/edit — front-line fillers plus owner/manager,
        // who hold every clinic-scoped edit ability per the codebase norm.
        'anamnesis.update' => ['owner', 'manager', 'doctor', 'assistant', 'receptionist'],
        // schedule exceptions — doctor manages own via policy ownership branch (not a permission)
        'scheduleExceptions.viewAny' => ['owner', 'manager', 'doctor', 'receptionist', 'assistant'],
        'scheduleExceptions.manage' => ['owner', 'manager', 'receptionist'],
        // appointments
        // Open the calendar / appointment list.
        'appointments.viewAny' => ['owner', 'manager', 'doctor', 'receptionist', 'assistant'],
        // See every doctor's appointments (absent → scoped to own doctors.id).
        // Doctors are excluded so they see only their own; non-doctor roles need full view.
        'appointments.viewAll' => ['owner', 'manager', 'receptionist', 'assistant'],
        'appointments.create' => ['owner', 'manager', 'doctor', 'receptionist'],
        // Book on behalf of any doctor. Without it, a doctor is locked to their own profile.
        'appointments.assignDoctor' => ['owner', 'manager', 'receptionist'],
        // Reschedule (edit slot / doctor / service / type / duration).
        // Doctors lack viewAll so the policy ownership branch confines them to their own appointments.
        'appointments.update' => ['owner', 'manager', 'receptionist', 'doctor'],
        // Cancel a non-terminal appointment (Confirmed / Rescheduled / Arrived).
        'appointments.cancel' => ['owner', 'manager', 'receptionist', 'doctor'],
        // Hard-delete a mis-created future Confirmed appointment. Restricted to managerial roles.
        'appointments.delete' => ['owner', 'manager'],
        // Bulk-cancel a date range of appointments. Higher blast radius than single cancel.
        'appointments.bulkCancel' => ['owner', 'manager'],
        // Manually send a reminder SMS for a specific appointment.
        'appointments.sendReminder' => ['owner', 'manager', 'receptionist'],
        // Check-in (mark Arrived) / manual no-show. Fixed role set per owner brief — all
        // clinic roles handle the front desk, no narrower restriction.
        'appointments.checkIn' => ['owner', 'manager', 'doctor', 'receptionist', 'assistant'],
        'appointments.noShow' => ['owner', 'manager', 'doctor', 'receptionist', 'assistant'],
        // appointment types (settings / CRUD)
        'appointmentTypes.viewAny' => ['owner', 'manager', 'doctor'],
        'appointmentTypes.create' => ['owner', 'manager'],
        'appointmentTypes.update' => ['owner', 'manager'],
        'appointmentTypes.delete' => ['owner', 'manager'],
        // treatments (Process screen)
        'treatments.viewAny' => ['owner', 'manager', 'doctor', 'receptionist', 'assistant'],
        // Absent → scoped to own doctor; doctors confined to own via policy ownership branch.
        'treatments.viewAll' => ['owner', 'manager', 'receptionist', 'assistant'],
        'treatments.create' => ['owner', 'manager', 'doctor', 'assistant'],
        // Void a completed treatment entered in error. Owner/manager void any treatment;
        // a doctor is confined to their own by the policy's ownership branch. Assistant and
        // receptionist are excluded — voiding rewrites clinical and financial record.
        'treatments.void' => ['owner', 'manager', 'doctor'],
        // Treatment media (photos/documents) — KVKK-min: doctor + assistant ONLY,
        // never owner/manager/receptionist (brief-fixed, unlike treatments.viewAll).
        'treatments.media.view' => ['doctor', 'assistant'],
        'treatments.media.upload' => ['doctor', 'assistant'],
        'treatments.media.delete' => ['doctor', 'assistant'],
        // Payment recording — exercised inside Process submit; fully exposed by 1.22.
        'transactions.create' => ['owner', 'manager', 'doctor', 'receptionist'],
        // Balance / transaction list display — hidden from assistant (money figures).
        'transactions.viewAny' => ['owner', 'manager', 'doctor', 'receptionist'],
        // Refund a payment. Owner + manager: both are full-authority clinic roles (owner
        // additionally owns tenant-level settings, which have no surface yet).
        'transactions.refund' => ['owner', 'manager'],
        // Payment plans (taksit) — collections screen; excludes doctor/assistant (money screen).
        'paymentPlans.viewAny' => ['owner', 'manager', 'receptionist'],
        // Mirrors transactions.create — plan created where payments are recorded.
        'paymentPlans.create' => ['owner', 'manager', 'doctor', 'receptionist'],
        // Managerial, higher blast radius — not in the brief; narrow set like appointments.delete.
        'paymentPlans.cancel' => ['owner', 'manager'],
        'paymentPlans.delete' => ['owner', 'manager'],
        // Manual "Hatırlat" — mirrors appointments.sendReminder.
        'paymentPlans.sendReminder' => ['owner', 'manager', 'receptionist'],
        // Installment collect reuses transactions.create (no dedicated permission).
        // Revenue report — clinic financial overview, management-only. Also gates the
        // single tabbed reports page (finance + doctor/service/product/appointment-type/
        // expense-owner breakdowns) and its Excel export; one permission for every tab.
        'reports.revenue' => ['owner', 'manager'],
        // Expenses — recording is every clinic role's own-record ("Giderlerim");
        // viewAny is the all-clinic list (finance page) and also the ownership
        // policy's "manage ANY expense" branch (no dedicated expenses.manage).
        'expenses.create' => ['owner', 'manager', 'doctor', 'receptionist', 'assistant'],
        'expenses.viewAny' => ['owner', 'manager'],
        // Cases
        // Open the case list.
        'cases.viewAny' => ['owner', 'manager', 'doctor', 'assistant'],
        // See every doctor's cases (absent → scoped to own doctor; assistant has no doctors row
        // so needs viewAll or the list would always be empty).
        'cases.viewAll' => ['owner', 'manager', 'assistant'],
        // Create a new case (also used to link treatments to a new case).
        'cases.create' => ['owner', 'manager', 'doctor'],
        // Mutate any case the user can view (status, notes, follow-up, title, link treatments).
        'cases.update' => ['owner', 'manager', 'doctor'],
        // Follow-up call widget — front-desk ("Bugün aranacaklar").
        // Doctors are intentionally excluded: the widget is clinic-wide, desk-only.
        'followUps.view' => ['owner', 'manager', 'receptionist'],
        // "Arandı" / cancel a follow-up (front-desk clinic-wide branch; a doctor completing
        // their OWN case's follow-up goes through the FollowUpPolicy ownership branch instead).
        'followUps.dismiss' => ['owner', 'manager', 'receptionist'],
        // Manual follow-up creation — widget header + case panel. Doctor included: today a
        // doctor already sets a case follow-up through cases.update; dropping it would remove
        // an existing capability.
        'followUps.create' => ['owner', 'manager', 'receptionist', 'doctor'],
        // Follow-up type definitions (settings CRUD) — tags.manage precedent (curated,
        // anti-drift list).
        'followUpTypes.manage' => ['owner', 'manager'],
        // SMS settings — per-type send toggles.
        'smsSettings.view' => ['owner', 'manager', 'receptionist'],
        'smsSettings.update' => ['owner', 'manager'],
        // SMS log — read-only list of sent/failed/queued messages.
        'smsLogs.viewAny' => ['owner', 'manager', 'receptionist'],
        // Tag-definition CRUD (settings). Applying/removing existing tags on a patient
        // uses patients.update instead — reception applies tags, doesn't define them.
        'tags.manage' => ['owner', 'manager'],
        // Saved segment (filter preset) create/delete. Applying one / the list's tag +
        // last-visit filters need no permission — read-only over patients.viewAny.
        'segments.manage' => ['owner', 'manager'],
        // Opens the read-only permission matrix page (this wave).
        'roles.viewAny' => ['owner', 'manager'],
        // Seeded now; enforced in Dalga 8b (matrix editing / custom roles).
        'roles.manage' => ['owner', 'manager'],
    ];

    public function run(): void
    {
        // Permissions and roles are global records (clinic_id null) — only the
        // role↔user assignment is clinic-scoped. Pin team context to null so the
        // permission/role rows resolve to the global ones.
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $permissionsByRole = [];

        foreach (self::PERMISSIONS as $name => $roles) {
            Permission::findOrCreate($name, 'web');

            foreach ($roles as $role) {
                $permissionsByRole[$role][] = $name;
            }
        }

        // syncPermissions is authoritative — re-running heals any drift. Explicit global
        // lookup (never Role::findByName, which could resolve a clinic's copy) so a clinic
        // customization is never touched by re-seeding.
        foreach ($permissionsByRole as $role => $permissions) {
            Role::query()->where('name', $role)->where('guard_name', 'web')->whereNull('clinic_id')
                ->firstOrFail()->syncPermissions($permissions);
        }
    }
}
