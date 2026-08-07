import FollowUpTypeFields from '@/components/follow-up-types/FollowUpTypeFields.vue';
import { store, update } from '@/routes/follow-up-types';
import type { CrudResource } from '@/types/crud';
import type { FollowUpType, FollowUpTypeFormData } from '@/types/followUpType';

export const followUpTypeResource: CrudResource<
    FollowUpTypeFormData,
    FollowUpType
> = {
    lang: 'follow_up_type',
    store,
    update,
    empty: () => ({ name: '', is_active: true }),
    toForm: (type) => ({ name: type.name, is_active: type.is_active }),
    fields: FollowUpTypeFields,
};
