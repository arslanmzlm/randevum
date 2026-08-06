<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { IconPlus, IconSearch, IconTag, IconTrash } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import CrudDialog from '@/components/crud/CrudDialog.vue';
import DataTableWrapper from '@/components/DataTableWrapper.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import TagChip from '@/components/TagChip.vue';
import { useCrudDialog } from '@/composables/useCrudDialog';
import { useTableFilters } from '@/composables/useTableFilters';
import { tagResource } from '@/crud/tag';
import AppLayout from '@/layouts/AppLayout.vue';
import { destroy, index } from '@/routes/tags';
import type { TagIndexProps, TagWithCount } from '@/types/tag';

defineOptions({ layout: AppLayout });

const props = defineProps<TagIndexProps>();

const { t } = useI18n();

const { visible, item, openCreate, openEdit, confirmDelete } =
    useCrudDialog<TagWithCount>({
        lang: tagResource.lang,
        destroy,
        editing: () => props.editing,
    });

// Same server-side list contract as the appointment types screen, so both behave alike.
const { state, loading, first, sortField, sortOrder, onPage, onSort } =
    useTableFilters({
        url: index().url,
        only: ['tags', 'query'],
        currentPage: props.tags.meta.current_page,
        search: props.query.filter.search,
        sort: props.query.sort,
        perPage: props.query.per_page,
    });

const showEmptyState = computed(
    () => props.tags.data.length === 0 && !state.search,
);
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
            v-if="showEmptyState"
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

        <DataTableWrapper
            v-else
            :value="tags.data"
            :total-records="tags.meta.total"
            :rows="state.per_page"
            :first="first"
            :loading="loading"
            :sort-field="sortField"
            :sort-order="sortOrder"
            @page="onPage"
            @sort="onSort"
        >
            <template #toolbar>
                <IconField>
                    <InputIcon>
                        <IconSearch class="size-4 text-surface-400" />
                    </InputIcon>
                    <InputText
                        v-model="state.search"
                        :placeholder="t('tag.search_placeholder')"
                        class="w-full sm:w-72"
                    />
                </IconField>
            </template>

            <Column field="name" :header="t('tag.columns.name')" sortable>
                <template #body="{ data }">
                    <TagChip :label="data.name" :color="data.color" />
                </template>
            </Column>

            <Column
                field="patients_count"
                :header="t('tag.columns.patients_count')"
                class="w-40"
                sortable
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
                            @click="confirmDelete(data, data.name)"
                        >
                            <IconTrash />
                        </Button>
                    </div>
                </template>
            </Column>
            <template #empty>
                <div class="px-6 py-10 text-center text-sm text-surface-500">
                    {{ t('tag.empty_filtered') }}
                </div>
            </template>
        </DataTableWrapper>

        <CrudDialog
            v-model:visible="visible"
            :resource="tagResource"
            :item="item"
        />
    </div>
</template>
