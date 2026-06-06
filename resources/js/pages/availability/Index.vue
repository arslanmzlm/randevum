<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { IconCalendarOff, IconPlus, IconTrash } from '@tabler/icons-vue';
import { useConfirm } from 'primevue/useconfirm';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import { useCan } from '@/composables/useCan';
import AppLayout from '@/layouts/AppLayout.vue';
import { destroy, index, store } from '@/routes/schedule-exceptions';
import type {
    AvailabilityIndexProps,
    ScheduleException,
    ScheduleExceptionFormData,
} from '@/types/availability';

defineOptions({ layout: AppLayout });

const props = defineProps<AvailabilityIndexProps>();

const { t, locale } = useI18n();
const confirm = useConfirm();
const { can } = useCan();
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

const dateFmt = new Intl.DateTimeFormat(locale.value, {
    timeZone: props.timezone,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
});
const dateTimeFmt = new Intl.DateTimeFormat(locale.value, {
    timeZone: props.timezone,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});
const timeFmt = new Intl.DateTimeFormat(locale.value, {
    timeZone: props.timezone,
    hour: '2-digit',
    minute: '2-digit',
});

function formatRange(exception: ScheduleException): string {
    const start = new Date(exception.starts_at);
    const end = new Date(exception.ends_at);
    const startDate = dateFmt.format(start);
    const endDate = dateFmt.format(end);

    if (exception.is_all_day) {
        return startDate === endDate ? startDate : `${startDate} – ${endDate}`;
    }

    if (startDate === endDate) {
        return `${startDate} ${timeFmt.format(start)} – ${timeFmt.format(end)}`;
    }

    return `${dateTimeFmt.format(start)} – ${dateTimeFmt.format(end)}`;
}

function isPast(exception: ScheduleException): boolean {
    return new Date(exception.ends_at).getTime() < Date.now();
}

const dialogVisible = ref(false);

// Picker controls bind to Date objects; on submit they're serialized to
// clinic-local naive strings (the backend interprets them in the clinic tz).
const rangeDates = ref<Date[] | null>(null);
const startDateTime = ref<Date | null>(null);
const endDateTime = ref<Date | null>(null);

const doctorOptions = computed(() =>
    props.doctors.map((d) => ({ label: d.display_name, value: d.id })),
);

// Manage-capable users add for anyone/clinic-wide; a doctor adds only for their
// own profile. A read-only assistant (no manage, no profile) can't add at all.
const canAdd = computed(() => canManage.value || props.ownDoctorId !== null);

const scopeOptions = computed(() => [
    { label: t('availability.scope_doctor'), value: 'doctor' as const },
    { label: t('availability.scope_clinic'), value: 'clinic' as const },
]);

const form = useForm<ScheduleExceptionFormData>({
    scope: 'doctor',
    doctor_id: canManage.value ? null : props.ownDoctorId,
    is_all_day: false,
    starts_at: '',
    ends_at: '',
    reason: '',
});

function openDialog(): void {
    form.reset();
    form.clearErrors();
    form.scope = 'doctor';
    form.doctor_id = canManage.value ? null : props.ownDoctorId;
    rangeDates.value = null;
    startDateTime.value = null;
    endDateTime.value = null;
    dialogVisible.value = true;
}

function pad(value: number): string {
    return String(value).padStart(2, '0');
}

function dateOnly(date: Date): string {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function dateTime(date: Date): string {
    return `${dateOnly(date)} ${pad(date.getHours())}:${pad(date.getMinutes())}:00`;
}

function submit(): void {
    form.transform((data) => {
        const payload = { ...data };

        if (data.is_all_day) {
            const range = rangeDates.value ?? [];
            const start = range[0] ?? null;
            const end = range[1] ?? range[0] ?? null;
            payload.starts_at = start ? dateOnly(start) : '';
            payload.ends_at = end ? dateOnly(end) : '';
        } else {
            payload.starts_at = startDateTime.value
                ? dateTime(startDateTime.value)
                : '';
            payload.ends_at = endDateTime.value
                ? dateTime(endDateTime.value)
                : '';
        }

        if (data.scope === 'clinic') {
            payload.doctor_id = null;
        }

        return payload;
    });

    form.post(store().url, {
        preserveScroll: true,
        onSuccess: () => {
            dialogVisible.value = false;
        },
    });
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

        <div
            v-if="exceptions.length === 0"
            class="flex flex-col items-center justify-center gap-3 rounded-xl border border-surface-200 bg-surface-0 px-6 py-16 text-center"
        >
            <IconCalendarOff class="size-10 text-surface-300" />
            <p class="text-sm text-surface-500">
                {{
                    !showPast && hasPast
                        ? t('availability.empty_has_past')
                        : t('availability.empty')
                }}
            </p>
        </div>

        <section
            v-else
            class="rounded-xl border border-surface-200 bg-surface-0 p-2 sm:p-3"
        >
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
                                {{ formatRange(data) }}
                            </span>
                            <Tag
                                v-if="data.is_all_day"
                                severity="info"
                                :value="t('availability.all_day_badge')"
                            />
                            <Tag
                                v-if="isPast(data)"
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
        </section>

        <Dialog
            v-model:visible="dialogVisible"
            modal
            dismissable-mask
            :draggable="false"
            :header="t('availability.dialog_title')"
            class="w-full max-w-lg"
        >
            <form
                novalidate
                class="flex flex-col gap-5 pt-2"
                @submit.prevent="submit"
            >
                <SelectButton
                    v-if="canManage"
                    v-model="form.scope"
                    :options="scopeOptions"
                    option-label="label"
                    option-value="value"
                    :allow-empty="false"
                    :aria-label="t('availability.scope_doctor')"
                />

                <p
                    v-if="form.scope === 'clinic'"
                    class="rounded-lg bg-surface-100 px-4 py-3 text-sm text-surface-600"
                >
                    {{ t('availability.clinic_wide_hint') }}
                </p>

                <FormField
                    v-if="canManage && form.scope === 'doctor'"
                    :label="t('availability.fields.doctor')"
                    :error="form.errors.doctor_id"
                    required
                >
                    <Select
                        v-model="form.doctor_id"
                        :options="doctorOptions"
                        option-label="label"
                        option-value="value"
                        fluid
                    />
                </FormField>

                <div
                    class="flex items-center justify-between gap-4 rounded-lg border border-surface-200 p-4"
                >
                    <span class="text-sm font-medium text-surface-900">
                        {{ t('availability.all_day') }}
                    </span>
                    <ToggleSwitch v-model="form.is_all_day" />
                </div>

                <FormField
                    v-if="form.is_all_day"
                    :label="t('availability.fields.date_range')"
                    :error="form.errors.starts_at || form.errors.ends_at"
                    required
                >
                    <DatePicker
                        v-model="rangeDates"
                        selection-mode="range"
                        date-format="dd.mm.yy"
                        :manual-input="false"
                        fluid
                    />
                </FormField>

                <template v-else>
                    <FormField
                        :label="t('availability.fields.starts_at')"
                        :error="form.errors.starts_at"
                        required
                    >
                        <DatePicker
                            v-model="startDateTime"
                            show-time
                            hour-format="24"
                            date-format="dd.mm.yy"
                            :manual-input="false"
                            fluid
                        />
                    </FormField>

                    <FormField
                        :label="t('availability.fields.ends_at')"
                        :error="form.errors.ends_at"
                        required
                    >
                        <DatePicker
                            v-model="endDateTime"
                            show-time
                            hour-format="24"
                            date-format="dd.mm.yy"
                            :manual-input="false"
                            fluid
                        />
                    </FormField>
                </template>

                <FormField
                    :label="t('availability.fields.reason')"
                    :error="form.errors.reason"
                >
                    <InputText v-model="form.reason" fluid />
                </FormField>

                <div class="flex justify-end gap-2 pt-2">
                    <Button
                        type="button"
                        severity="secondary"
                        text
                        :label="t('common.cancel')"
                        @click="dialogVisible = false"
                    />
                    <Button
                        type="submit"
                        :label="t('availability.save')"
                        :loading="form.processing"
                    />
                </div>
            </form>
        </Dialog>
    </div>
</template>
