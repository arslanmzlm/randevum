<script setup lang="ts">
import {
    IconAlertTriangle,
    IconCalendarPlus,
    IconCheck,
    IconLoader2,
} from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import { useAvailabilityCheck } from '@/composables/useAvailabilityCheck';
import type {
    FollowUpInterval,
    FollowUpMode,
    TreatmentServiceOption,
} from '@/types/treatment';
import {
    clampTime,
    combineDateTime,
    isValidTime,
} from '@/utils/appointmentTime';
import { useTreatmentForm } from './formContext';

const props = defineProps<{
    doctorId: number;
    services: TreatmentServiceOption[];
}>();

const { t } = useI18n();

const form = useTreatmentForm();

const minDate = new Date();

const modeOptions: Array<{ value: FollowUpMode; label: string }> = [
    { value: 'none', label: t('treatment.follow_up.mode_none') },
    { value: 'single', label: t('treatment.follow_up.mode_single') },
    { value: 'package', label: t('treatment.follow_up.mode_package') },
];

const intervalOptions: Array<{ value: FollowUpInterval; label: string }> = [
    { value: 'weekly', label: t('treatment.follow_up.interval_weekly') },
    { value: 'biweekly', label: t('treatment.follow_up.interval_biweekly') },
    { value: 'monthly', label: t('treatment.follow_up.interval_monthly') },
];

const serviceOptions = computed(() =>
    props.services.map((s) => ({ value: s.id, label: s.name })),
);

const isActive = computed(() => form.follow_up.mode !== 'none');

// Nested dotted error keys (follow_up.*) aren't part of the form's typed top-level error map.
const fieldError = (key: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[key];

const startsAtError = computed<string | undefined>(() =>
    fieldError('follow_up.starts_at'),
);

function onTimeBlur(): void {
    form.follow_up.time = clampTime(form.follow_up.time);
}

// Advisory pre-check on the FIRST occurrence only — the server runs the full 3-layer check per
// occurrence and skips conflicts. Mirrors the create-appointment hint.
const { state: availabilityState, reason: availabilityReason } =
    useAvailabilityCheck(() => {
        if (!isActive.value) {
            return null;
        }

        const startsAt = combineDateTime(
            form.follow_up.date,
            form.follow_up.time,
        );

        if (!startsAt) {
            return null;
        }

        return {
            doctor_id: props.doctorId,
            starts_at: startsAt,
            duration_minutes: null,
            service_id: form.follow_up.service_id,
            is_walk_in: false,
        };
    });

const availabilityMessage = computed<string | null>(() => {
    switch (availabilityState.value) {
        case 'checking':
            return t('appointment.availability.checking');
        case 'available':
            return t('appointment.availability.available');
        case 'unavailable':
            return availabilityReason.value
                ? t(`appointment.errors.${availabilityReason.value}`)
                : t('appointment.availability.unavailable');
        default:
            return null;
    }
});
</script>

<template>
    <section
        class="flex flex-col gap-5 rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
    >
        <header class="flex items-center gap-2">
            <IconCalendarPlus class="size-5 text-surface-500" />
            <h2 class="text-lg font-semibold text-surface-900">
                {{ t('treatment.sections.follow_up') }}
            </h2>
        </header>

        <div class="flex flex-wrap gap-4">
            <div
                v-for="option in modeOptions"
                :key="option.value"
                class="flex items-center gap-2"
            >
                <RadioButton
                    v-model="form.follow_up.mode"
                    :input-id="`follow-up-${option.value}`"
                    :value="option.value"
                />
                <label
                    :for="`follow-up-${option.value}`"
                    class="cursor-pointer text-sm text-surface-700"
                >
                    {{ option.label }}
                </label>
            </div>
        </div>

        <template v-if="isActive">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="form-group">
                    <label class="mb-1 block text-sm text-muted">
                        {{ t('treatment.follow_up.date') }}
                        <span class="text-red-500"> *</span>
                    </label>
                    <DatePicker
                        v-model="form.follow_up.date"
                        :min-date="minDate"
                        date-format="dd.mm.yy"
                        show-icon
                        :invalid="
                            Boolean(startsAtError) && !form.follow_up.date
                        "
                        fluid
                    />
                </div>

                <div class="form-group">
                    <label class="mb-1 block text-sm text-muted">
                        {{ t('treatment.follow_up.time') }}
                        <span class="text-red-500"> *</span>
                    </label>
                    <InputMask
                        v-model="form.follow_up.time"
                        mask="99:99"
                        :placeholder="t('appointment.time_placeholder')"
                        :invalid="
                            Boolean(startsAtError) &&
                            !isValidTime(form.follow_up.time)
                        "
                        fluid
                        @blur="onTimeBlur"
                    />
                </div>

                <FormField
                    :label="t('treatment.follow_up.service')"
                    :error="fieldError('follow_up.service_id')"
                    :hint="t('treatment.follow_up.service_hint')"
                >
                    <Select
                        v-model="form.follow_up.service_id"
                        :options="serviceOptions"
                        option-label="label"
                        option-value="value"
                        filter
                        show-clear
                        fluid
                    />
                </FormField>

                <template v-if="form.follow_up.mode === 'package'">
                    <FormField
                        :label="t('treatment.follow_up.count')"
                        :error="fieldError('follow_up.count')"
                        :hint="t('treatment.follow_up.count_hint')"
                    >
                        <InputNumber
                            v-model="form.follow_up.count"
                            :min="2"
                            :max="12"
                            show-buttons
                            :use-grouping="false"
                            fluid
                        />
                    </FormField>

                    <FormField
                        :label="t('treatment.follow_up.interval')"
                        :error="fieldError('follow_up.interval')"
                    >
                        <Select
                            v-model="form.follow_up.interval"
                            :options="intervalOptions"
                            option-label="label"
                            option-value="value"
                            fluid
                        />
                    </FormField>
                </template>
            </div>

            <small v-if="startsAtError" class="text-xs text-red-500">
                {{ startsAtError }}
            </small>

            <p
                v-else-if="availabilityMessage"
                class="flex items-center gap-1.5 text-xs"
                :class="{
                    'text-surface-500': availabilityState === 'checking',
                    'text-green-600': availabilityState === 'available',
                    'text-amber-600': availabilityState === 'unavailable',
                }"
            >
                <IconLoader2
                    v-if="availabilityState === 'checking'"
                    class="size-3.5 animate-spin"
                />
                <IconCheck
                    v-else-if="availabilityState === 'available'"
                    class="size-3.5"
                />
                <IconAlertTriangle v-else class="size-3.5" />
                {{ availabilityMessage }}
            </p>

            <p
                v-if="form.follow_up.mode === 'package'"
                class="text-xs text-surface-400"
            >
                {{ t('treatment.follow_up.package_hint') }}
            </p>
        </template>
    </section>
</template>
