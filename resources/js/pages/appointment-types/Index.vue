<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { IconPlus, IconSearch, IconTags, IconTrash } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import CrudDialog from '@/components/crud/CrudDialog.vue';
import DataTableWrapper from '@/components/DataTableWrapper.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useCan } from '@/composables/useCan';
import { useCrudDialog } from '@/composables/useCrudDialog';
import { useTableFilters } from '@/composables/useTableFilters';
import { appointmentTypeResource } from '@/crud/appointmentType';
import AppLayout from '@/layouts/AppLayout.vue';
import { destroy, index } from '@/routes/appointment-types';
import type {
    AppointmentType,
    AppointmentTypeIndexProps,
} from '@/types/appointmentType';

defineOptions({ layout: AppLayout });

const props = defineProps<AppointmentTypeIndexProps>();

const { t } = useI18n();
const { can } = useCan();

// One control per ability, mirroring what the server enforces on each route.
const canCreate = computed(() => can('appointmentTypes.create'));
const canUpdate = computed(() => can('appointmentTypes.update'));
const canDelete = computed(() => can('appointmentTypes.delete'));
const showActions = computed(() => canUpdate.value || canDelete.value);

const { visible, item, openCreate, openEdit, confirmDelete } =
    useCrudDialog<AppointmentType>({
        lang: appointmentTypeResource.lang,
        destroy,
        editing: () => props.editing,
        canCreate: () => canCreate.value,
    });

const { state, loading, first, sortField, sortOrder, onPage, onSort } =
    useTableFilters<{ is_active: boolean | null }>({
        url: index().url,
        only: ['appointmentTypes', 'query'],
        currentPage: props.appointmentTypes.meta.current_page,
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
    () => props.appointmentTypes.meta.total === 0 && !hasActiveFilters.value,
);

const statusOptions = computed(() => [
    { label: t('appointment_type.active'), value: true },
    { label: t('appointment_type.passive'), value: false },
]);
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('appointment_type.title')" />

        <PageHeader
            :title="t('appointment_type.title')"
            :description="t('appointment_type.subtitle')"
            :breadcrumbs="[{ label: t('nav.appointment_types') }]"
        >
            <template #actions>
                <Button
                    v-if="canCreate"
                    type="button"
                    :label="t('appointment_type.add')"
                    @click="openCreate"
                >
                    <template #icon>
                        <IconPlus />
                    </template>
                </Button>
            </template>
        </PageHeader>

        <CrudDialog
            v-if="canCreate || canUpdate"
            v-model:visible="visible"
            :resource="appointmentTypeResource"
            :item="item"
        />

        <EmptyState
            v-if="showEmptyState"
            :icon="IconTags"
            :message="t('appointment_type.empty')"
        />

        <DataTableWrapper
            v-else
            :value="appointmentTypes.data"
            :total-records="appointmentTypes.meta.total"
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
                        :placeholder="t('appointment_type.search_placeholder')"
                        class="w-full sm:w-72"
                    />
                </IconField>
                <Select
                    v-model="state.is_active"
                    :options="statusOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="t('appointment_type.filter_status')"
                    show-clear
                    class="w-full sm:w-44"
                />
            </template>

            <Column
                field="name"
                :header="t('appointment_type.columns.name')"
                sortable
            >
                <template #body="{ data }">
                    <div class="flex min-w-0 items-center gap-2">
                        <span
                            class="size-3.5 shrink-0 rounded-full"
                            :style="{ backgroundColor: data.color }"
                            :aria-hidden="true"
                        />
                        <span class="truncate font-medium text-surface-900">
                            {{ data.name }}
                        </span>
                    </div>
                </template>
            </Column>

            <Column
                field="default_duration_minutes"
                :header="t('appointment_type.columns.duration')"
                sortable
                class="w-40"
            >
                <template #body="{ data }">
                    <span class="text-surface-700">
                        {{
                            t('appointment_type.minutes', {
                                minutes: data.default_duration_minutes,
                            })
                        }}
                    </span>
                </template>
            </Column>

            <Column
                field="is_active"
                :header="t('appointment_type.columns.status')"
                sortable
                class="w-32"
            >
                <template #body="{ data }">
                    <Tag
                        :severity="data.is_active ? 'success' : 'secondary'"
                        :value="
                            data.is_active
                                ? t('appointment_type.active')
                                : t('appointment_type.passive')
                        "
                    />
                </template>
            </Column>

            <Column
                v-if="showActions"
                :header="t('appointment_type.columns.actions')"
                class="w-32"
            >
                <template #body="{ data }">
                    <div class="flex items-center justify-end gap-1">
                        <Button
                            v-if="canUpdate"
                            type="button"
                            :label="t('appointment_type.edit')"
                            severity="secondary"
                            outlined
                            size="small"
                            @click="openEdit(data)"
                        />
                        <Button
                            v-if="canDelete"
                            type="button"
                            severity="danger"
                            text
                            size="small"
                            :aria-label="t('appointment_type.remove')"
                            @click="confirmDelete(data, data.name)"
                        >
                            <IconTrash />
                        </Button>
                    </div>
                </template>
            </Column>

            <template #empty>
                <div class="px-6 py-10 text-center text-sm text-surface-500">
                    {{ t('appointment_type.empty_filtered') }}
                </div>
            </template>
        </DataTableWrapper>
    </div>
</template>
