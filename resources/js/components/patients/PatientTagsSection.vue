<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconTag } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import SectionCard from '@/components/SectionCard.vue';
import TagChip from '@/components/TagChip.vue';
import { useCan } from '@/composables/useCan';
import { sync } from '@/routes/patients/tags';
import type { Patient } from '@/types/patient';
import type { Tag } from '@/types/tag';

const props = defineProps<{
    patient: Patient;
    /** All active-clinic tags — options for the add picker. */
    allTags: Tag[];
}>();

const { t } = useI18n();
const { can } = useCan();

const canManage = computed(() => can('patients.update'));

const currentTags = computed<Tag[]>(() => props.patient.tags ?? []);

// The edit picker's working set of tag ids — a full-replace sync target.
const selectedIds = ref<number[]>(currentTags.value.map((tag) => tag.id));

watch(currentTags, (tags) => {
    selectedIds.value = tags.map((tag) => tag.id);
});

const form = useForm<{ tag_ids: number[] }>({ tag_ids: [] });

const isDirty = computed(() => {
    const current = new Set(currentTags.value.map((tag) => tag.id));

    return (
        selectedIds.value.length !== current.size ||
        selectedIds.value.some((id) => !current.has(id))
    );
});

function persist(ids: number[]): void {
    form.tag_ids = ids;
    form.put(sync(props.patient.id).url, { preserveScroll: true });
}

function save(): void {
    persist([...selectedIds.value]);
}

// Removing a chip is a client edit persisted immediately via the full-replace sync
// (no ConfirmDialog — the gate is irreversible server effects, not tag detach).
function removeTag(id: number): void {
    persist(selectedIds.value.filter((tagId) => tagId !== id));
}
</script>

<template>
    <SectionCard :icon="IconTag" :title="t('tag.section_title')">
        <div class="flex flex-col gap-4">
            <div
                v-if="currentTags.length"
                class="flex flex-wrap items-center gap-2"
            >
                <TagChip
                    v-for="tag in currentTags"
                    :key="tag.id"
                    :label="tag.name"
                    :color="tag.color"
                    :removable="canManage"
                    @remove="removeTag(tag.id)"
                />
            </div>
            <p v-else class="text-sm text-surface-400">
                {{ t('tag.none') }}
            </p>

            <div v-if="canManage" class="flex flex-col gap-2 sm:flex-row">
                <MultiSelect
                    v-model="selectedIds"
                    :options="allTags"
                    option-label="name"
                    option-value="id"
                    :placeholder="t('tag.add_placeholder')"
                    :max-selected-labels="0"
                    :selected-items-label="`{0} ${t('tag.selected_suffix')}`"
                    filter
                    class="w-full sm:w-80"
                >
                    <template #option="{ option }">
                        <TagChip :label="option.name" :color="option.color" />
                    </template>
                    <template #empty>
                        <span class="text-sm text-surface-500">
                            {{ t('tag.no_options') }}
                        </span>
                    </template>
                </MultiSelect>
                <Button
                    type="button"
                    :label="t('tag.save')"
                    :disabled="!isDirty"
                    :loading="form.processing"
                    @click="save"
                />
            </div>
        </div>
    </SectionCard>
</template>
