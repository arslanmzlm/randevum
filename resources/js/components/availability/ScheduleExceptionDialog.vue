<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import SettingRow from '@/components/SettingRow.vue';
import { store } from '@/routes/schedule-exceptions';
import type {
    AvailabilityDoctorOption,
    ScheduleExceptionFormData,
} from '@/types/availability';

const props = defineProps<{
    doctors: AvailabilityDoctorOption[];
    canManage: boolean;
    ownDoctorId: number | null;
}>();

const visible = defineModel<boolean>('visible', { required: true });

const { t } = useI18n();

const doctorOptions = computed(() =>
    props.doctors.map((d) => ({ label: d.display_name, value: d.id })),
);

const scopeOptions = computed(() => [
    { label: t('availability.scope_doctor'), value: 'doctor' as const },
    { label: t('availability.scope_clinic'), value: 'clinic' as const },
]);

// Picker controls bind to Date objects; on submit they're serialized to
// clinic-local naive strings (the backend interprets them in the clinic tz).
const rangeDates = ref<Date[] | null>(null);
const startDateTime = ref<Date | null>(null);
const endDateTime = ref<Date | null>(null);

const form = useForm<ScheduleExceptionFormData>({
    scope: 'doctor',
    doctor_id: props.canManage ? null : props.ownDoctorId,
    is_all_day: false,
    starts_at: '',
    ends_at: '',
    reason: '',
});

watch(visible, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.reset();
    form.clearErrors();
    form.scope = 'doctor';
    form.doctor_id = props.canManage ? null : props.ownDoctorId;
    rangeDates.value = null;
    startDateTime.value = null;
    endDateTime.value = null;
});

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
            visible.value = false;
        },
    });
}
</script>

<template>
    <Dialog
        v-model:visible="visible"
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

            <SettingRow :label="t('availability.all_day')">
                <ToggleSwitch v-model="form.is_all_day" />
            </SettingRow>

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
                    @click="visible = false"
                />
                <Button
                    type="submit"
                    :label="t('availability.save')"
                    :loading="form.processing"
                />
            </div>
        </form>
    </Dialog>
</template>
