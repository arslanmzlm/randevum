import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Reads the active-clinic-scoped permissions shared in `auth.permissions` and exposes a
 * reactive `can(permission)` check for UI gating. Mirror the exact permission the server
 * enforces (e.g. `can('patients.delete')`) — never branch on role names. This gates UX
 * only; the server still authorizes every action.
 */
export function useCan() {
    const page = usePage();

    const permissions = computed(
        () => new Set(page.props.auth?.permissions ?? []),
    );

    const can = (permission: string): boolean =>
        permissions.value.has(permission);

    return { can };
}
