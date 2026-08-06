<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    IconBan,
    IconBell,
    IconCalendarEvent,
    IconCalendarOff,
    IconCalendarWeek,
    IconDotsVertical,
    IconFileDescription,
    IconPencil,
    IconSearch,
    IconStethoscope,
    IconTrash,
    IconUserCheck,
    IconUserX,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    bulkCancelPage,
    bulkCreatePage,
} from '@/actions/App/Modules/Scheduling/Http/Controllers/AppointmentController';
import AppointmentCancelDialog from '@/components/appointments/AppointmentCancelDialog.vue';
import AppointmentStatusTag from '@/components/AppointmentStatusTag.vue';
import DataTableWrapper from '@/components/DataTableWrapper.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useAppointmentActions } from '@/composables/useAppointmentActions';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import { useTableFilters } from '@/composables/useTableFilters';
import { useTreatmentActions } from '@/composables/useTreatmentActions';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, show as appointmentShow } from '@/routes/appointments';
import { show } from '@/routes/patients';
import type {
    AppointmentIndexProps,
    AppointmentListItem,
} from '@/types/appointment';
import { MVP_APPOINTMENT_STATUSES } from '@/utils/appointmentStatus';
import { parseDateString } from '@/utils/datetime';
import { FILTER_NONE } from '@/utils/filterValues';
import { shouldFilterSelect } from '@/utils/selectFilter';

defineOptions({ layout: AppLayout });

const props = defineProps<AppointmentIndexProps>();

const { t } = useI18n();
const { can } = useCan();
const { formatDate, formatTime } = useDateTime();

const {
    canViewAll,
    cancelReason,
    canCheckIn,
    canMarkNoShow,
    canReschedule,
    canCancel,
    canDelete,
    canSendReminder,
    goToEdit,
    checkIn,
    confirmMarkNoShow,
    confirmCancel,
    confirmDelete,
    confirmSendReminder,
} = useAppointmentActions(props.ownDoctorId);

const {
    canStartTreatment,
    isResume,
    startTreatment,
    canViewTreatment,
    viewTreatment,
} = useTreatmentActions(props.ownDoctorId);

// One shared popup Menu retargeted per row on the kebab click (PrimeVue pattern).
const actionsMenu = ref();
const activeRow = ref<AppointmentListItem | null>(null);

// `tablerIcon` (not `icon`) because PrimeVue's MenuItem.icon is a string (PrimeIcon class);
// the Tabler component is rendered in the #item slot instead. `colorClass` mirrors the popover's
// severity colours (edit = primary, cancel = warn, delete = danger).
type RowMenuItem = {
    key: string;
    label: string;
    tablerIcon: unknown;
    colorClass: string;
    command: () => void;
};

const menuItems = computed<RowMenuItem[]>(() => {
    const row = activeRow.value;

    if (!row) {
        return [];
    }

    const items: RowMenuItem[] = [];

    if (canStartTreatment(row)) {
        items.push({
            key: 'treatment',
            label: isResume(row)
                ? t('treatment.actions.resume')
                : t('treatment.actions.start'),
            tablerIcon: IconStethoscope,
            colorClass: 'text-primary-600',
            command: () => startTreatment(row),
        });
    }

    if (canCheckIn(row)) {
        items.push({
            key: 'check-in',
            label: t('appointment_actions.menu.check_in'),
            tablerIcon: IconUserCheck,
            colorClass: 'text-primary-600',
            command: () => checkIn(row),
        });
    }

    // A started-but-unfinished appointment is both startable and has a Draft; "start" already
    // resumes it, so only offer "view" once the appointment is past the startable statuses.
    if (!canStartTreatment(row) && canViewTreatment(row)) {
        items.push({
            key: 'view-treatment',
            label: t('treatment.actions.view'),
            tablerIcon: IconStethoscope,
            colorClass: 'text-primary-600',
            command: () => viewTreatment(row),
        });
    }

    if (canReschedule(row)) {
        items.push({
            key: 'edit',
            label: t('appointment_actions.menu.edit'),
            tablerIcon: IconPencil,
            colorClass: 'text-primary-600',
            command: () => goToEdit(row),
        });
    }

    if (canSendReminder(row)) {
        items.push({
            key: 'send-reminder',
            label: t('appointment_actions.send_reminder'),
            tablerIcon: IconBell,
            colorClass: 'text-primary-600',
            command: () => confirmSendReminder(row),
        });
    }

    items.push({
        key: 'detail',
        label: t('appointment_actions.menu.detail'),
        tablerIcon: IconFileDescription,
        colorClass: 'text-primary-600',
        command: () => router.visit(appointmentShow(row.id).url),
    });

    if (canMarkNoShow(row)) {
        items.push({
            key: 'no-show',
            label: t('appointment_actions.menu.no_show'),
            tablerIcon: IconUserX,
            colorClass: 'text-orange-600',
            command: () => confirmMarkNoShow(row),
        });
    }

    if (canCancel(row)) {
        items.push({
            key: 'cancel',
            label: t('appointment_actions.menu.cancel'),
            tablerIcon: IconBan,
            colorClass: 'text-orange-600',
            command: () => confirmCancel(row),
        });
    }

    if (canDelete(row)) {
        items.push({
            key: 'delete',
            label: t('appointment_actions.menu.delete'),
            tablerIcon: IconTrash,
            colorClass: 'text-red-600',
            command: () => confirmDelete(row),
        });
    }

    return items;
});

function toggleMenu(event: Event, row: AppointmentListItem): void {
    activeRow.value = row;
    actionsMenu.value?.toggle(event);
}

const { state, loading, first, sortField, sortOrder, onPage, onSort } =
    useTableFilters<{
        status: string[];
        doctor_id: string[];
        service_id: string | null;
        appointment_type_id: string | null;
        start_date: Date | null;
        end_date: Date | null;
    }>({
        url: index().url,
        only: ['appointments', 'query'],
        currentPage: props.appointments.meta.current_page,
        search: props.query.filter.search,
        sort: props.query.sort,
        perPage: props.query.per_page,
        filters: {
            status: {
                type: 'array',
                value: props.query.filter.status
                    ? props.query.filter.status.split(',')
                    : [],
            },
            doctor_id: {
                type: 'array',
                value: props.query.filter.doctor_id ?? [],
            },
            service_id: {
                type: 'string',
                value: props.query.filter.service_id || null,
            },
            appointment_type_id: {
                type: 'string',
                value: props.query.filter.appointment_type_id || null,
            },
            start_date: {
                type: 'date',
                value: props.query.filter.start_date
                    ? parseDateString(props.query.filter.start_date)
                    : null,
            },
            end_date: {
                type: 'date',
                value: props.query.filter.end_date
                    ? parseDateString(props.query.filter.end_date)
                    : null,
            },
        },
    });

// MultiSelect binds numeric doctor ids; the filter state keeps string ids (URL/CSV canonical form).
const selectedDoctorIds = computed<number[]>({
    get: () => state.doctor_id.map(Number),
    set: (ids) => {
        state.doctor_id = ids.map(String);
    },
});

const hasActiveFilters = computed(
    () =>
        !!state.search ||
        state.status.length > 0 ||
        state.doctor_id.length > 0 ||
        !!state.service_id ||
        !!state.appointment_type_id ||
        state.start_date !== null ||
        state.end_date !== null,
);

// Big empty state only when the clinic genuinely has no appointments (not a filtered miss).
const showEmptyState = computed(
    () => props.appointments.meta.total === 0 && !hasActiveFilters.value,
);

const statusOptions = computed(() =>
    MVP_APPOINTMENT_STATUSES.map((status) => ({
        label: t(`appointment.status.${status}`),
        value: status,
    })),
);

// "Belirtilmemiş" is the last plain option (not a group) — it filters the column's NULL rows.
const serviceFilterOptions = computed(() => [
    ...props.services.map((service) => ({
        label: service.name,
        value: String(service.id),
    })),
    { label: t('common.unspecified'), value: FILTER_NONE },
]);

const appointmentTypeFilterOptions = computed(() => [
    ...props.appointmentTypes.map((type) => ({
        label: type.name,
        value: String(type.id),
        color: type.color as string | null,
    })),
    { label: t('common.unspecified'), value: FILTER_NONE, color: null },
]);

// PrimeVue range DatePicker binds one [start, end] array; bridge it to the two
// separate filter params. Mid-selection the array is [start, null] (valid partial filter).
const dateRange = computed<(Date | null)[] | null>({
    get: () =>
        state.start_date || state.end_date
            ? [state.start_date, state.end_date]
            : null,
    set: (value) => {
        state.start_date = value?.[0] ?? null;
        state.end_date = value?.[1] ?? null;
    },
});
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('appointment_list.title')" />

        <PageHeader
            :title="t('appointment_list.title')"
            :description="t('appointment_list.subtitle')"
            :breadcrumbs="[{ label: t('nav.appointments') }]"
        >
            <template #actions>
                <Button
                    v-if="can('appointments.create')"
                    type="button"
                    outlined
                    :label="t('appointment_bulk.title')"
                    @click="router.visit(bulkCreatePage().url)"
                >
                    <template #icon>
                        <IconCalendarEvent class="size-4" />
                    </template>
                </Button>
                <Button
                    v-if="can('appointments.bulkCancel')"
                    type="button"
                    severity="warn"
                    outlined
                    :label="t('appointment_bulk_cancel.title')"
                    @click="router.visit(bulkCancelPage().url)"
                >
                    <template #icon>
                        <IconCalendarOff class="size-4" />
                    </template>
                </Button>
            </template>
        </PageHeader>

        <EmptyState
            v-if="showEmptyState"
            :icon="IconCalendarWeek"
            :message="t('appointment_list.empty')"
        />

        <DataTableWrapper
            v-else
            :value="appointments.data"
            :total-records="appointments.meta.total"
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
                        :placeholder="t('appointment_list.search_placeholder')"
                        class="w-full sm:w-72"
                    />
                </IconField>
                <MultiSelect
                    v-model="state.status"
                    :options="statusOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="t('appointment_list.filter_status')"
                    :max-selected-labels="2"
                    show-clear
                    class="w-full sm:w-64"
                />
                <MultiSelect
                    v-if="canViewAll"
                    v-model="selectedDoctorIds"
                    :options="doctors"
                    option-label="display_name"
                    option-value="id"
                    :placeholder="t('appointment_list.filter_doctor')"
                    :max-selected-labels="0"
                    :selected-items-label="`{0} ${t('common.doctor_selected_suffix')}`"
                    show-clear
                    :filter="shouldFilterSelect(doctors.length)"
                    :filter-placeholder="t('common.search')"
                    class="w-full sm:w-52"
                />
                <Select
                    v-if="services.length"
                    v-model="state.service_id"
                    :options="serviceFilterOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="t('appointment_list.filter_service')"
                    show-clear
                    :filter="shouldFilterSelect(serviceFilterOptions.length)"
                    :filter-placeholder="t('common.search')"
                    class="w-full sm:w-52"
                />
                <Select
                    v-if="appointmentTypes.length"
                    v-model="state.appointment_type_id"
                    :options="appointmentTypeFilterOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="t('appointment_list.filter_type')"
                    show-clear
                    :filter="
                        shouldFilterSelect(appointmentTypeFilterOptions.length)
                    "
                    :filter-placeholder="t('common.search')"
                    class="w-full sm:w-52"
                >
                    <template #option="{ option }">
                        <span class="flex items-center gap-2">
                            <span
                                v-if="option.color"
                                class="size-2.5 shrink-0 rounded-full"
                                :style="{ backgroundColor: option.color }"
                            />
                            {{ option.label }}
                        </span>
                    </template>
                </Select>
                <DatePicker
                    v-model="dateRange"
                    selection-mode="range"
                    :number-of-months="2"
                    :manual-input="false"
                    date-format="dd.mm.yy"
                    show-button-bar
                    :placeholder="t('appointment_list.filter_date_range')"
                    class="w-full sm:w-64"
                    :pt="{ panel: { class: 'daterange-panel-centered' } }"
                />
            </template>

            <Column
                field="starts_at"
                :header="t('appointment_list.columns.datetime')"
                sortable
                class="w-48"
            >
                <template #body="{ data }">
                    <div class="flex flex-col">
                        <span class="font-medium text-surface-800">
                            {{ formatDate(data.starts_at) }}
                        </span>
                        <span class="text-xs text-surface-500">
                            {{ formatTime(data.starts_at) }} –
                            {{ formatTime(data.ends_at) }}
                        </span>
                    </div>
                </template>
            </Column>

            <Column :header="t('appointment_list.columns.patient')">
                <template #body="{ data }">
                    <div class="flex min-w-0 items-center gap-2">
                        <Link
                            :href="show(data.patient_id).url"
                            class="truncate font-medium text-primary-600 transition-colors hover:text-primary-700 hover:underline"
                        >
                            {{ data.patient_name }}
                        </Link>
                        <Tag
                            v-if="data.is_walk_in"
                            severity="warn"
                            :value="t('appointment_list.walk_in_badge')"
                        />
                    </div>
                </template>
            </Column>

            <Column :header="t('appointment_list.columns.doctor')">
                <template #body="{ data }">
                    <span class="text-surface-700">
                        {{ data.doctor_name }}
                    </span>
                </template>
            </Column>

            <Column :header="t('appointment_list.columns.service')">
                <template #body="{ data }">
                    <span v-if="data.service_name" class="text-surface-700">
                        {{ data.service_name }}
                    </span>
                    <span
                        v-else-if="data.appointment_type"
                        class="inline-flex items-center gap-2 text-surface-700"
                    >
                        <span
                            class="size-2.5 shrink-0 rounded-full"
                            :style="{
                                backgroundColor: data.appointment_type.color,
                            }"
                        />
                        {{ data.appointment_type.name }}
                    </span>
                    <span v-else class="text-surface-400">—</span>
                </template>
            </Column>

            <Column
                field="status"
                :header="t('appointment_list.columns.status')"
                class="w-44"
            >
                <template #body="{ data }">
                    <AppointmentStatusTag :status="data.status" />
                </template>
            </Column>

            <Column
                :header="t('appointment_list.columns.actions')"
                class="w-20"
            >
                <template #body="{ data }">
                    <div class="flex justify-end">
                        <!-- Always shown: "Detaya git" is available on every visible row. -->
                        <Button
                            type="button"
                            severity="secondary"
                            text
                            rounded
                            size="small"
                            :aria-label="t('appointment_actions.row_actions')"
                            aria-haspopup="true"
                            @click="toggleMenu($event, data)"
                        >
                            <IconDotsVertical class="size-4" />
                        </Button>
                    </div>
                </template>
            </Column>

            <template #empty>
                <div class="px-6 py-10 text-center text-sm text-surface-500">
                    {{ t('appointment_list.empty_filtered') }}
                </div>
            </template>
        </DataTableWrapper>

        <Menu ref="actionsMenu" :model="menuItems" :popup="true">
            <template #item="{ item, props: itemProps }">
                <a
                    class="flex items-center gap-2"
                    :class="item.colorClass"
                    v-bind="itemProps.action"
                >
                    <component :is="item.tablerIcon" class="size-4 shrink-0" />
                    <span>{{ item.label }}</span>
                </a>
            </template>
        </Menu>

        <AppointmentCancelDialog v-model="cancelReason" />
    </div>
</template>
