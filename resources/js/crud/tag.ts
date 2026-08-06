import TagFields from '@/components/tags/TagFields.vue';
import { store, update } from '@/routes/tags';
import type { CrudResource } from '@/types/crud';
import type { TagFormData, TagWithCount } from '@/types/tag';
import { COLOR_PRESETS } from '@/utils/colorPresets';

export const tagResource: CrudResource<TagFormData, TagWithCount> = {
    lang: 'tag',
    // One step wider than the default so the colour presets stay on a single row.
    width: 'w-full max-w-lg',
    store,
    update,
    empty: () => ({ name: '', color: COLOR_PRESETS[0] }),
    toForm: (tag) => ({ name: tag.name, color: tag.color }),
    fields: TagFields,
};
