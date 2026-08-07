<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    IconPhoneCall,
    IconPlus,
    IconSearch,
    IconTrash,
} from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import CrudDialog from '@/components/crud/CrudDialog.vue';
import DataTableWrapper from '@/components/DataTableWrapper.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useCan } from '@/composables/useCan';
import { useCrudDialog } from '@/composables/useCrudDialog';
import { useTableFilters } from '@/composables/useTableFilters';
import { followUpTypeResource } from '@/crud/followUpType';
import AppLayout from '@/layouts/AppLayout.vue';
import { destroy, index } from '@/routes/follow-up-types';
import type {
    FollowUpType,
    FollowUpTypeIndexProps,
} from '@/types/followUpType';

defineOptions({ layout: AppLayout });

const props = defineProps<FollowUpTypeIndexProps>();

const { t } = useI18n();
const { can } = useCan();

// One coarse permission covers the whole definition list, matching FollowUpTypePolicy.
const canManage = computed(() => can('followUpTypes.manage'));

const { visible, item, openCreate, openEdit, confirmDelete } =
    useCrudDialog<FollowUpType>({
        lang: followUpTypeResource.lang,
        destroy,
        editing: () => props.editing,
        canCreate: () => canManage.value,
    });

const { state, loading, first, sortField, sortOrder, onPage, onSort } =
    useTableFilters<{ is_active: boolean | null }>({
        url: index().url,
        only: ['followUpTypes', 'query'],
        currentPage: props.followUpTypes.meta.current_page,
        search: props.query.filter.search,
        sort: props.query.sort,
        perPage: props.query.per_page,
        filters: {
            is_active: {
                type: 'boolean',
                value: props.query.filter.is_active,
            },
        },
    });

const hasActiveFilters = computed(
    () => !!state.search || state.is_active !== null,
);

// Big empty state only when the clinic genuinely has no types (not a filtered miss).
const showEmptyState = computed(
    () => props.followUpTypes.meta.total === 0 && !hasActiveFilters.value,
);

const statusOptions = computed(() => [
    { label: t('follow_up_type.active'), value: true },
    { label: t('follow_up_type.passive'), value: false },
]);
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('follow_up_type.title')" />

        <PageHeader
            :title="t('follow_up_type.title')"
            :description="t('follow_up_type.subtitle')"
            :breadcrumbs="[{ label: t('nav.follow_up_types') }]"
        >
            <template #actions>
                <Button
                    v-if="canManage"
                    type="button"
                    :label="t('follow_up_type.add')"
                    @click="openCreate"
                >
                    <template #icon>
                        <IconPlus />
                    </template>
                </Button>
            </template>
        </PageHeader>

        <CrudDialog
            v-if="canManage"
            v-model:visible="visible"
            :resource="followUpTypeResource"
            :item="item"
        />

        <EmptyState
            v-if="showEmptyState"
            :icon="IconPhoneCall"
            :message="t('follow_up_type.empty')"
        />

        <DataTableWrapper
            v-else
            :value="followUpTypes.data"
            :total-records="followUpTypes.meta.total"
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
                        :placeholder="t('follow_up_type.search_placeholder')"
                        class="w-full sm:w-72"
                    />
                </IconField>
                <Select
                    v-model="state.is_active"
                    :options="statusOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="t('follow_up_type.filter_status')"
                    show-clear
                    class="w-full sm:w-44"
                />
            </template>

            <Column
                field="name"
                :header="t('follow_up_type.columns.name')"
                sortable
            >
                <template #body="{ data }">
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="truncate font-medium text-surface-900">
                            {{ data.name }}
                        </span>
                        <Tag
                            v-if="data.is_system"
                            severity="secondary"
                            class="p-tag-sm whitespace-nowrap"
                            :value="t('follow_up_type.system')"
                        />
                    </div>
                </template>
            </Column>

            <Column
                field="is_active"
                :header="t('follow_up_type.columns.status')"
                sortable
                class="w-32"
            >
                <template #body="{ data }">
                    <Tag
                        :severity="data.is_active ? 'success' : 'secondary'"
                        :value="
                            data.is_active
                                ? t('follow_up_type.active')
                                : t('follow_up_type.passive')
                        "
                    />
                </template>
            </Column>

            <Column
                v-if="canManage"
                :header="t('follow_up_type.columns.actions')"
                class="w-32"
            >
                <template #body="{ data }">
                    <div class="flex items-center justify-end gap-1">
                        <Button
                            type="button"
                            :label="t('follow_up_type.edit')"
                            severity="secondary"
                            outlined
                            size="small"
                            @click="openEdit(data)"
                        />
                        <!-- Seeded types are deactivated, never removed (the server refuses too). -->
                        <Button
                            v-if="!data.is_system"
                            type="button"
                            severity="danger"
                            text
                            size="small"
                            :aria-label="t('follow_up_type.remove')"
                            @click="confirmDelete(data, data.name)"
                        >
                            <IconTrash />
                        </Button>
                    </div>
                </template>
            </Column>

            <template #empty>
                <div class="px-6 py-10 text-center text-sm text-surface-500">
                    {{ t('follow_up_type.empty_filtered') }}
                </div>
            </template>
        </DataTableWrapper>
    </div>
</template>
