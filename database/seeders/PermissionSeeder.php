<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Permission => the baseline roles that hold it. Only the abilities the app
     * actually enforces today; this grows feature by feature, not as a full
     * up-front matrix (permission-management UI is Faz 3).
     *
     * @var array<string, list<string>>
     */
    private const PERMISSIONS = [
        'clinic.update' => ['owner'],
        'doctors.viewAny' => ['owner', 'manager', 'doctor', 'receptionist', 'assistant'],
        'doctors.create' => ['owner', 'manager'],
        'doctors.update' => ['owner', 'manager'],
        'doctors.delete' => ['owner', 'manager'],
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
        // appointment types (settings / CRUD)
        'appointmentTypes.viewAny' => ['owner', 'manager', 'doctor'],
        'appointmentTypes.create' => ['owner', 'manager'],
        'appointmentTypes.update' => ['owner', 'manager'],
        'appointmentTypes.delete' => ['owner', 'manager'],
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

        // syncPermissions is authoritative — re-running heals any drift.
        foreach ($permissionsByRole as $role => $permissions) {
            Role::findByName($role, 'web')->syncPermissions($permissions);
        }
    }
}
