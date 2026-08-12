import RoleFields from '@/components/settings/RoleFields.vue';
import { store, update } from '@/routes/settings/roles';
import type { CrudResource } from '@/types/crud';
import type { RoleColumn, RoleFormData } from '@/types/role';

// `update` renames a custom role — the dialog's edit mode. Baseline copies never reach it: the
// matrix only wires the rename action for `role.is_custom` columns (see PermissionMatrix.vue).
export const roleResource: CrudResource<RoleFormData, RoleColumn> = {
    lang: 'role',
    store,
    update,
    empty: () => ({ name: '' }),
    toForm: (role) => ({ name: role.name }),
    fields: RoleFields,
};
