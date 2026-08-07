import RoleFields from '@/components/settings/RoleFields.vue';
import { store } from '@/routes/settings/roles';
import type { CrudResource } from '@/types/crud';
import type { RoleColumn, RoleFormData } from '@/types/role';

// No `update`: a custom role cannot be renamed, so the shared dialog only ever creates.
export const roleResource: CrudResource<RoleFormData, RoleColumn> = {
    lang: 'role',
    store,
    empty: () => ({ name: '' }),
    fields: RoleFields,
};
