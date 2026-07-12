<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { IconCalendarOff, IconPlus, IconTrash } from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ScheduleExceptionDialog from '@/components/availability/ScheduleExceptionDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import AppLayout from '@/layouts/AppLayout.vue';
import { destroy, index } from '@/routes/schedule-exceptions';
import type {
    AvailabilityIndexProps,
    ScheduleException,
} from '@/types/availability';

defineOptions({ layout: AppLayout });

const props = defineProps<AvailabilityIndexProps>();

const { t } = useI18n();
const confirm = useConfirm();
const { can } = useCan();
const { formatRange, isPast } = useDateTime();
// scheduleExceptions.manage: clinic-wide + other-doctor add (self-only otherwise).
const canManage = computed(() => can('scheduleExceptions.manage'));

const doctorFilter = ref<number | null>(null);

// Local toggle mirrors the server's ?show_past — preserveState keeps this value
// across the reload, so the switch and the loaded data stay in sync.
const pastVisible = ref(props.showPast);

function togglePast(): void {
    router.get(
        index().url,
        { show_past: pastVisible.value },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

const filteredExceptions = computed(() => {
    if (doctorFilter.value === null) {
        return props.exceptions;
    }

    return props.exceptions.filter((e) => e.doctor_id === doctorFilter.value);
});

function formatExceptionRange(exception: ScheduleException): string {
    return formatRange(exception.starts_at, exception.ends_at, {
        allDay: exception.is_all_day,
    });
}

function isExceptionPast(exception: ScheduleException): boolean {
    return isPast(exception.ends_at);
}

const dialogVisible = ref(false);

const doctorOptions = computed(() =>
    props.doctors.map((d) => ({ label: d.display_name, value: d.id })),
);

// Manage-capable users add for anyone/clinic-wide; a doctor adds only for their
// own profile. A read-only assistant (no manage, no profile) can't add at all.
const canAdd = computed(() => canManage.value || props.ownDoctorId !== null);

function openDialog(): void {
    dialogVisible.value = true;
}

function removeException(exception: ScheduleException): void {
    confirm.require({
        header: t('common.confirm_title'),
        message: t('availability.remove_confirm'),
        rejectProps: {
            label: t('common.cancel'),
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: t('common.delete'), severity: 'danger' },
        accept: () =>
            router.delete(destroy(exception.id).url, { preserveScroll: true }),
    });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('availability.title')" />

        <PageHeader
            :title="t('availability.title')"
            :description="t('availability.subtitle')"
            :breadcrumbs="[{ label: t('nav.availability') }]"
        >
            <template #actions>
                <Button
                    v-if="canAdd"
                    type="button"
                    :label="t('availability.add')"
                    @click="openDialog"
                >
                    <template #icon>
                        <IconPlus />
                    </template>
                </Button>
            </template>
        </PageHeader>

        <label
            v-if="showPast || hasPast"
            class="flex items-center gap-2 self-end text-sm text-surface-600"
        >
            <ToggleSwitch
                v-model="pastVisible"
                @update:model-value="togglePast"
            />
            {{ t('availability.show_past') }}
        </label>

        <EmptyState
            v-if="exceptions.length === 0"
            :icon="IconCalendarOff"
            :message="
                !showPast && hasPast
                    ? t('availability.empty_has_past')
                    : t('availability.empty')
            "
        />

        <SectionCard v-else :padding="'p-2 sm:p-3'">
            <div
                v-if="doctors.length > 1"
                class="flex flex-col gap-2 p-2 sm:flex-row sm:items-center"
            >
                <Select
                    v-model="doctorFilter"
                    :options="doctorOptions"
                    option-label="label"
                    option-value="value"
                    :placeholder="t('availability.filter_doctor')"
                    show-clear
                    class="w-full sm:w-72"
                />
            </div>

            <DataTable
                :value="filteredExceptions"
                data-key="id"
                class="text-sm"
            >
                <Column
                    field="doctor_name"
                    :header="t('availability.columns.doctor')"
                >
                    <template #body="{ data }">
                        <span class="font-medium text-surface-900">
                            {{ data.doctor_name }}
                        </span>
                    </template>
                </Column>

                <Column :header="t('availability.columns.range')">
                    <template #body="{ data }">
                        <div class="flex items-center gap-2">
                            <span class="text-surface-700">
                                {{ formatExceptionRange(data) }}
                            </span>
                            <Tag
                                v-if="data.is_all_day"
                                severity="info"
                                :value="t('availability.all_day_badge')"
                            />
                            <Tag
                                v-if="isExceptionPast(data)"
                                severity="secondary"
                                :value="t('availability.past_badge')"
                            />
                        </div>
                    </template>
                </Column>

                <Column :header="t('availability.columns.reason')">
                    <template #body="{ data }">
                        <span class="text-surface-600">
                            {{ data.reason || '—' }}
                        </span>
                    </template>
                </Column>

                <Column
                    field="created_by_name"
                    :header="t('availability.columns.created_by')"
                    class="w-40"
                >
                    <template #body="{ data }">
                        <span class="text-surface-500">
                            {{ data.created_by_name || '—' }}
                        </span>
                    </template>
                </Column>

                <Column
                    :header="t('availability.columns.actions')"
                    class="w-24"
                >
                    <template #body="{ data }">
                        <div class="flex items-center justify-end">
                            <Button
                                v-if="data.can_delete"
                                type="button"
                                severity="danger"
                                text
                                size="small"
                                :aria-label="t('availability.remove')"
                                @click="removeException(data)"
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
                        {{ t('availability.empty_filtered') }}
                    </div>
                </template>
            </DataTable>
        </SectionCard>

        <ScheduleExceptionDialog
            v-model:visible="dialogVisible"
            :doctors="doctors"
            :can-manage="canManage"
            :own-doctor-id="ownDoctorId"
        />
    </div>
</template>
