<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { IconPlus, IconTag, IconTrash } from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import SectionCard from '@/components/SectionCard.vue';
import TagChip from '@/components/TagChip.vue';
import TagFormDialog from '@/components/tags/TagFormDialog.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { destroy } from '@/routes/tags';
import type { TagIndexProps, TagWithCount } from '@/types/tag';

defineOptions({ layout: AppLayout });

const props = defineProps<TagIndexProps>();

const { t } = useI18n();
const confirm = useConfirm();

const tagRows = computed<TagWithCount[]>(() => props.tags.data);

const dialogVisible = ref(false);
const editing = ref<TagWithCount | null>(null);

function openCreate(): void {
    editing.value = null;
    dialogVisible.value = true;
}

function openEdit(tag: TagWithCount): void {
    editing.value = tag;
    dialogVisible.value = true;
}

function removeTag(tag: TagWithCount): void {
    confirm.require({
        header: t('common.confirm_title'),
        message: t('tag.remove_confirm', { name: tag.name }),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: t('common.delete'), severity: 'danger' },
        accept: () =>
            router.delete(destroy(tag.id).url, { preserveScroll: true }),
    });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('tag.title')" />

        <PageHeader
            :title="t('tag.title')"
            :description="t('tag.subtitle')"
            :breadcrumbs="[{ label: t('nav.tags') }]"
        >
            <template #actions>
                <Button type="button" :label="t('tag.add')" @click="openCreate">
                    <template #icon>
                        <IconPlus />
                    </template>
                </Button>
            </template>
        </PageHeader>

        <EmptyState
            v-if="tagRows.length === 0"
            :icon="IconTag"
            :message="t('tag.empty')"
        >
            <template #action>
                <Button
                    type="button"
                    size="small"
                    :label="t('tag.add')"
                    @click="openCreate"
                >
                    <template #icon>
                        <IconPlus />
                    </template>
                </Button>
            </template>
        </EmptyState>

        <SectionCard v-else padding="p-2 sm:p-3">
            <DataTable :value="tagRows" data-key="id">
                <Column :header="t('tag.columns.name')">
                    <template #body="{ data }">
                        <TagChip :label="data.name" :color="data.color" />
                    </template>
                </Column>

                <Column
                    field="patients_count"
                    :header="t('tag.columns.patients_count')"
                    class="w-40"
                >
                    <template #body="{ data }">
                        <span class="text-surface-700">
                            {{
                                t('tag.patients_count', {
                                    count: data.patients_count,
                                })
                            }}
                        </span>
                    </template>
                </Column>

                <Column :header="t('tag.columns.actions')" class="w-32">
                    <template #body="{ data }">
                        <div class="flex items-center justify-end gap-1">
                            <Button
                                type="button"
                                severity="secondary"
                                outlined
                                size="small"
                                :label="t('tag.edit')"
                                @click="openEdit(data)"
                            />
                            <Button
                                type="button"
                                severity="danger"
                                text
                                size="small"
                                :aria-label="t('tag.remove')"
                                @click="removeTag(data)"
                            >
                                <IconTrash />
                            </Button>
                        </div>
                    </template>
                </Column>
            </DataTable>
        </SectionCard>

        <TagFormDialog v-model:visible="dialogVisible" :tag="editing" />
    </div>
</template>
