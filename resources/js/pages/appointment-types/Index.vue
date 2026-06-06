<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { IconPlus, IconSearch, IconTags, IconTrash } from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import DataTableWrapper from '@/components/DataTableWrapper.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useCan } from '@/composables/useCan';
import { useTableFilters } from '@/composables/useTableFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { create, destroy, edit, index } from '@/routes/appointment-types';
import type {
    AppointmentType,
    AppointmentTypeIndexProps,
} from '@/types/appointmentType';

defineOptions({ layout: AppLayout });

const props = defineProps<AppointmentTypeIndexProps>();

const { t } = useI18n();
const confirm = useConfirm();
const { can } = useCan();
const canManage = computed(() => can('appointmentTypes.create'));

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

function removeType(appointmentType: AppointmentType): void {
    confirm.require({
        header: t('common.confirm_title'),
        message: t('appointment_type.remove_confirm', {
            name: appointmentType.name,
        }),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: t('common.delete'), severity: 'danger' },
        accept: () =>
            router.delete(destroy(appointmentType.id).url, {
                preserveScroll: true,
            }),
    });
}
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
                <ButtonLink
                    v-if="canManage"
                    :href="create().url"
                    :label="t('appointment_type.add')"
                >
                    <template #icon>
                        <IconPlus />
                    </template>
                </ButtonLink>
            </template>
        </PageHeader>

        <div
            v-if="showEmptyState"
            class="flex flex-col items-center justify-center gap-3 rounded-xl border border-surface-200 bg-surface-0 px-6 py-16 text-center"
        >
            <IconTags class="size-10 text-surface-300" />
            <p class="text-sm text-surface-500">
                {{ t('appointment_type.empty') }}
            </p>
        </div>

        <section
            v-else
            class="rounded-xl border border-surface-200 bg-surface-0 p-2 sm:p-3"
        >
            <DataTableWrapper
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
                            :placeholder="
                                t('appointment_type.search_placeholder')
                            "
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
                    v-if="canManage"
                    :header="t('appointment_type.columns.actions')"
                    class="w-32"
                >
                    <template #body="{ data }">
                        <div class="flex items-center justify-end gap-1">
                            <ButtonLink
                                :href="edit(data.id).url"
                                :label="t('appointment_type.edit')"
                                severity="secondary"
                                outlined
                                size="small"
                            />
                            <Button
                                type="button"
                                severity="danger"
                                text
                                size="small"
                                :aria-label="t('appointment_type.remove')"
                                @click="removeType(data)"
                            >
                                <IconTrash />
                            </Button>
                        </div>
                    </template>
                </Column>

                <template #empty>
                    <div
                        class="px-6 py-10 text-center text-sm text-surface-500"
                    >
                        {{ t('appointment_type.empty_filtered') }}
                    </div>
                </template>
            </DataTableWrapper>
        </section>
    </div>
</template>
