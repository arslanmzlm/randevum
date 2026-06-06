<script setup lang="ts">
import {
    IconAlertTriangle,
    IconCalendarEvent,
    IconCheck,
    IconClockHour4,
    IconLoader2,
} from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAvailabilityCheck } from '@/composables/useAvailabilityCheck';
import {
    clampTime,
    combineDateTime,
    isValidTime,
} from '@/utils/appointmentTime';
import { useAppointmentForm } from './formContext';

const { t } = useI18n();

const form = useAppointmentForm();

const minDate = new Date();

// starts_at is a server-only key (the transform builds it from date + time), so it isn't part of
// the form's typed error map — read it through a loosened view.
const startsAtError = computed<string | undefined>(
    () => (form.errors as Record<string, string | undefined>).starts_at,
);

// A starts_at error means the combined value was rejected; flag the specific empty/invalid side
// (inline pickers have no input element, so we also surface a ring + message, not just :invalid).
const dateInvalid = computed(() => Boolean(startsAtError.value) && !form.date);
const timeInvalid = computed(
    () => Boolean(startsAtError.value) && !isValidTime(form.time),
);

// Live availability pre-check — fires once a doctor + valid date/time are chosen and re-runs on
// any slot change. Advisory: the server POST stays the real gate (walk-in + races can lag it).
const { state: availabilityState, reason: availabilityReason } =
    useAvailabilityCheck(() => {
        const startsAt = combineDateTime(form.date, form.time);

        if (!form.doctor_id || !startsAt) {
            return null;
        }

        return {
            doctor_id: form.doctor_id,
            starts_at: startsAt,
            duration_minutes: form.duration_minutes,
            service_id: form.service_id,
            is_walk_in: form.is_walk_in,
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

// Keep the masked input from holding an impossible time (e.g. 99:99).
function onTimeBlur(): void {
    form.time = clampTime(form.time);
}
</script>

<template>
    <div
        class="py-6 first:pt-0 last:pb-0 lg:px-6 lg:py-0 lg:first:pl-0 lg:last:pr-0"
    >
        <header class="mb-6 flex items-center gap-2">
            <IconCalendarEvent class="size-5 text-surface-500" />
            <h2 class="text-lg font-semibold text-surface-900">
                {{ t('appointment.sections.datetime') }}
            </h2>
        </header>

        <div class="flex flex-col gap-4">
            <div class="form-group">
                <label class="mb-1 block text-sm text-muted">
                    {{ t('appointment.fields.date')
                    }}<span class="text-red-500"> *</span>
                </label>
                <DatePicker
                    v-model="form.date"
                    inline
                    :min-date="minDate"
                    :invalid="dateInvalid"
                    class="w-full"
                    :class="{ 'datepicker-invalid': dateInvalid }"
                />
            </div>

            <div class="form-group">
                <label class="mb-1 block text-sm text-muted">
                    {{ t('appointment.fields.time')
                    }}<span class="text-red-500"> *</span>
                </label>
                <InputMask
                    v-model="form.time"
                    mask="99:99"
                    :placeholder="t('appointment.time_placeholder')"
                    :invalid="timeInvalid"
                    fluid
                    @blur="onTimeBlur"
                />
                <small v-if="startsAtError" class="text-xs text-red-500">
                    {{ startsAtError }}
                </small>

                <!-- Live availability hint — advisory only; the POST is the real gate. -->
                <p
                    v-if="!startsAtError && availabilityMessage"
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
            </div>
        </div>

        <Button
            type="submit"
            :label="t('appointment.submit')"
            :loading="form.processing"
            class="mt-6 w-full"
        >
            <template #icon>
                <IconClockHour4 />
            </template>
        </Button>
    </div>
</template>
