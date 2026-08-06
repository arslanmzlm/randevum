<script setup lang="ts">
import { IconCalendarPlus } from '@tabler/icons-vue';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppointmentTypeSelect from '@/components/AppointmentTypeSelect.vue';
import AvailabilityBadge from '@/components/AvailabilityBadge.vue';
import FormField from '@/components/FormField.vue';
import ModeSelectRow from '@/components/ModeSelectRow.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useAvailabilityCheck } from '@/composables/useAvailabilityCheck';
import type {
    FollowUpAppointmentTypeOption,
    FollowUpInterval,
    FollowUpMode,
    FollowUpOccurrenceForm,
    TreatmentServiceOption,
} from '@/types/treatment';
import { clampTime, combineDateTime } from '@/utils/appointmentTime';
import { offsetDate } from '@/utils/followUpOccurrences';
import { shouldFilterSelect } from '@/utils/selectFilter';
import FollowUpOccurrenceRow from './FollowUpOccurrenceRow.vue';
import { useTreatmentForm } from './formContext';

const props = defineProps<{
    doctorId: number;
    services: TreatmentServiceOption[];
    appointmentTypes: FollowUpAppointmentTypeOption[];
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

const typeOptions = computed(() =>
    props.appointmentTypes.map((type) => ({
        value: type.id,
        label: type.name,
        color: type.color,
    })),
);

const isActive = computed(() => form.follow_up.mode !== 'none');
const isPackage = computed(() => form.follow_up.mode === 'package');

// Nested dotted error keys (follow_up.*) aren't part of the form's typed top-level error map.
const fieldError = (key: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[key];

// Single mode sends one occurrence built from date+time; package sends the row list. The whole
// list is server-validated as `follow_up.occurrences`, so surface that key on the package list too.
const occurrencesError = computed<string | undefined>(() =>
    fieldError('follow_up.occurrences'),
);

// Single mode: the occurrence error (per-row `.0` keys or the list-level required key) shows
// exactly once — under the date when it's the empty side, otherwise under the time.
const singleError = computed<string | undefined>(
    () =>
        fieldError('follow_up.occurrences.0.starts_at') ??
        fieldError('follow_up.occurrences.0') ??
        occurrencesError.value,
);

const singleDateError = computed<string | undefined>(() =>
    singleError.value && !form.follow_up.date ? singleError.value : undefined,
);

const singleTimeError = computed<string | undefined>(() =>
    singleError.value && !singleDateError.value ? singleError.value : undefined,
);

function onTimeBlur(): void {
    form.follow_up.time = clampTime(form.follow_up.time);
}

// Any generator-param change rebuilds the whole package list from the pattern — manual row edits
// are intentionally discarded (decided: keep simple). Empties the list until a valid start exists.
watch(
    () => [
        form.follow_up.mode,
        form.follow_up.date,
        form.follow_up.time,
        form.follow_up.count,
        form.follow_up.interval,
    ],
    () => {
        if (form.follow_up.mode !== 'package') {
            form.follow_up.occurrences = [];

            return;
        }

        const start = form.follow_up.date;

        if (!start || !combineDateTime(start, form.follow_up.time)) {
            form.follow_up.occurrences = [];

            return;
        }

        const count = Math.min(12, Math.max(2, form.follow_up.count || 2));
        const rows: FollowUpOccurrenceForm[] = [];

        for (let i = 0; i < count; i++) {
            rows.push({
                date: offsetDate(start, form.follow_up.interval, i),
                time: form.follow_up.time,
                duration_minutes: form.follow_up.duration_minutes,
                appointment_type_id: form.follow_up.appointment_type_id,
            });
        }

        form.follow_up.occurrences = rows;
    },
    { immediate: true },
);

// The generator-level type/duration only seed the rows: changing one re-applies that field to
// every row (overwriting per-row picks) but keeps hand-edited dates — no regeneration. Separate
// watches so re-seeding the type never clobbers per-row durations and vice versa.
watch(
    () => form.follow_up.appointment_type_id,
    (typeId) => {
        if (form.follow_up.mode === 'package') {
            for (const occurrence of form.follow_up.occurrences) {
                occurrence.appointment_type_id = typeId;
            }
        }
    },
);

watch(
    () => form.follow_up.duration_minutes,
    (duration) => {
        if (form.follow_up.mode === 'package') {
            for (const occurrence of form.follow_up.occurrences) {
                occurrence.duration_minutes = duration;
            }
        }
    },
);

function removeOccurrence(index: number): void {
    if (form.follow_up.occurrences.length <= 2) {
        return;
    }

    form.follow_up.occurrences.splice(index, 1);
}

// Single mode: advisory pre-check on the one occurrence. The server runs the full 3-layer check.
const { state: availabilityState, reason: availabilityReason } =
    useAvailabilityCheck(() => {
        if (form.follow_up.mode !== 'single') {
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
            duration_minutes: form.follow_up.duration_minutes,
            service_id: form.follow_up.service_id,
            appointment_type_id: form.follow_up.appointment_type_id,
            is_walk_in: false,
        };
    });
</script>

<template>
    <SectionCard
        :icon="IconCalendarPlus"
        :title="t('treatment.sections.follow_up')"
    >
        <div class="flex flex-col gap-5">
            <ModeSelectRow
                v-model="form.follow_up.mode"
                :options="modeOptions"
                id-prefix="follow-up"
            />

            <template v-if="isActive">
                <div
                    class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3"
                >
                    <FormField
                        v-if="!isPackage"
                        :label="t('treatment.follow_up.date')"
                        :error="singleDateError"
                        required
                    >
                        <DatePicker
                            v-model="form.follow_up.date"
                            :min-date="minDate"
                            date-format="dd.mm.yy"
                            show-icon
                            fluid
                        />
                    </FormField>

                    <FormField
                        v-if="!isPackage"
                        :label="t('treatment.follow_up.time')"
                        :error="singleTimeError"
                        required
                    >
                        <InputMask
                            v-model="form.follow_up.time"
                            mask="99:99"
                            fluid
                            @blur="onTimeBlur"
                        />
                    </FormField>

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
                            :filter="shouldFilterSelect(serviceOptions.length)"
                            :filter-placeholder="t('common.search')"
                            show-clear
                            fluid
                        />
                    </FormField>

                    <FormField
                        v-if="typeOptions.length"
                        :label="t('appointment.fields.appointment_type')"
                        :hint="
                            isPackage
                                ? t('treatment.follow_up.seed_hint')
                                : undefined
                        "
                    >
                        <AppointmentTypeSelect
                            v-model="form.follow_up.appointment_type_id"
                            :options="typeOptions"
                        />
                    </FormField>

                    <FormField
                        :label="t('appointment.fields.duration_minutes')"
                        :hint="
                            isPackage
                                ? t('treatment.follow_up.seed_hint')
                                : t('appointment.hints.duration_minutes')
                        "
                    >
                        <InputNumber
                            v-model="form.follow_up.duration_minutes"
                            :min="5"
                            :max="480"
                            :step="5"
                            suffix=" dk"
                            show-buttons
                            :use-grouping="false"
                            fluid
                        />
                    </FormField>

                    <template v-if="isPackage">
                        <FormField
                            :label="t('treatment.follow_up.date')"
                            required
                        >
                            <DatePicker
                                v-model="form.follow_up.date"
                                :min-date="minDate"
                                date-format="dd.mm.yy"
                                show-icon
                                fluid
                            />
                        </FormField>

                        <FormField
                            :label="t('treatment.follow_up.time')"
                            required
                        >
                            <InputMask
                                v-model="form.follow_up.time"
                                mask="99:99"
                                fluid
                                @blur="onTimeBlur"
                            />
                        </FormField>

                        <FormField
                            :label="t('treatment.follow_up.count')"
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

                        <FormField :label="t('treatment.follow_up.interval')">
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

                <!-- Single mode: the occurrence error renders inside the date/time FormFields; the
                 advisory badge only shows when there is no error to surface. -->
                <template v-if="!isPackage">
                    <AvailabilityBadge
                        v-if="!singleError"
                        :state="availabilityState"
                        :reason="availabilityReason"
                    />
                </template>

                <!-- Package mode: editable, removable occurrence rows with per-row availability. -->
                <template v-else>
                    <p class="text-xs text-surface-400">
                        {{ t('treatment.follow_up.regenerated_hint') }}
                    </p>

                    <!-- List-level error (required / min:2) must render even when the generated list is
                     empty (invalid generator start), so it lives outside the row-list wrapper. -->
                    <small v-if="occurrencesError" class="text-xs text-red-500">
                        {{ occurrencesError }}
                    </small>

                    <div v-if="form.follow_up.occurrences.length">
                        <h3 class="mb-3 text-sm font-semibold text-surface-700">
                            {{ t('treatment.follow_up.occurrences_title') }}
                        </h3>

                        <div class="grid grid-cols-1 gap-3">
                            <FollowUpOccurrenceRow
                                v-for="(occurrence, index) in form.follow_up
                                    .occurrences"
                                :key="index"
                                :index="index"
                                :doctor-id="doctorId"
                                :type-options="typeOptions"
                                :removable="
                                    form.follow_up.occurrences.length > 2
                                "
                                @remove="removeOccurrence(index)"
                            />
                        </div>
                    </div>
                </template>
            </template>
        </div>
    </SectionCard>
</template>
