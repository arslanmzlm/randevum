<script setup lang="ts">
import { IconCalendarPlus, IconPlus } from '@tabler/icons-vue';
import { computed, reactive, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppointmentTypeSelect from '@/components/AppointmentTypeSelect.vue';
import FormField from '@/components/FormField.vue';
import type { FollowUpInterval } from '@/types/treatment';
import { clampTime } from '@/utils/appointmentTime';
import { buildOccurrences, offsetDate } from '@/utils/followUpOccurrences';
import BulkOccurrenceRow from './BulkOccurrenceRow.vue';
import { useBulkAppointmentForm } from './formContext';

const props = defineProps<{
    doctorId: number | null;
    typeOptions: Array<{ label: string; value: number; color: string }>;
    defaultSlotDuration: number;
}>();

const { t } = useI18n();

const form = useBulkAppointmentForm();

const minDate = new Date();

const MAX_OCCURRENCES = 12;

// Generator params are client-only (they shape the row list but aren't submitted), so they live
// here rather than on the form — only `occurrences` is posted.
const gen = reactive<{
    date: Date | null;
    time: string;
    count: number;
    interval: FollowUpInterval;
    duration_minutes: number | null;
    appointment_type_id: number | null;
}>({
    date: null,
    time: '',
    count: 4,
    interval: 'weekly',
    duration_minutes: props.defaultSlotDuration,
    appointment_type_id: null,
});

const intervalOptions: Array<{ value: FollowUpInterval; label: string }> = [
    { value: 'weekly', label: t('treatment.follow_up.interval_weekly') },
    { value: 'biweekly', label: t('treatment.follow_up.interval_biweekly') },
    { value: 'monthly', label: t('treatment.follow_up.interval_monthly') },
];

function onTimeBlur(): void {
    gen.time = clampTime(gen.time);
}

// Nested dotted error keys aren't part of the form's typed top-level error map.
const fieldError = (key: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[key];

// List-level error (required / min:1 / max:12) — renders even when the generated list is empty.
const occurrencesError = computed<string | undefined>(() =>
    fieldError('occurrences'),
);

// Any core generator-param change rebuilds the whole list from the pattern — manual row edits are
// intentionally discarded (decided: keep simple, mirrors the treatment follow-up generator).
watch(
    () => [gen.date, gen.time, gen.count, gen.interval],
    () => {
        form.occurrences = buildOccurrences({
            startDate: gen.date,
            time: gen.time,
            count: gen.count,
            interval: gen.interval,
            seedDuration: gen.duration_minutes,
            seedTypeId: gen.appointment_type_id,
        });
    },
    { immediate: true },
);

// Seed duration/type only re-apply to existing rows (overwriting per-row picks) without
// regenerating — so hand-edited dates survive. Separate watches so one never clobbers the other.
watch(
    () => gen.appointment_type_id,
    (typeId) => {
        for (const occurrence of form.occurrences) {
            occurrence.appointment_type_id = typeId;
        }
    },
);

watch(
    () => gen.duration_minutes,
    (duration) => {
        for (const occurrence of form.occurrences) {
            occurrence.duration_minutes = duration;
        }
    },
);

const canAddRow = computed(() => form.occurrences.length < MAX_OCCURRENCES);

// Append one row spaced a further interval past the last (or the generator start), seeded from it.
function addRow(): void {
    if (!canAddRow.value) {
        return;
    }

    const last = form.occurrences[form.occurrences.length - 1];
    const base = last?.date ?? gen.date;

    form.occurrences.push({
        date: base ? offsetDate(base, gen.interval, 1) : null,
        time: last?.time || gen.time,
        duration_minutes: last?.duration_minutes ?? gen.duration_minutes,
        appointment_type_id:
            last?.appointment_type_id ?? gen.appointment_type_id,
    });
}

function removeOccurrence(index: number): void {
    if (form.occurrences.length <= 1) {
        return;
    }

    form.occurrences.splice(index, 1);
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <header class="flex items-center gap-2">
            <IconCalendarPlus class="size-5 text-surface-500" />
            <h2 class="text-lg font-semibold text-surface-900">
                {{ t('appointment_bulk.generator.title') }}
            </h2>
        </header>

        <p class="text-sm text-surface-500">
            {{ t('appointment_bulk.generator.hint') }}
        </p>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <FormField :label="t('appointment.fields.date')" required>
                <DatePicker
                    v-model="gen.date"
                    :min-date="minDate"
                    date-format="dd.mm.yy"
                    show-icon
                    fluid
                />
            </FormField>

            <FormField :label="t('appointment.fields.time')" required>
                <InputMask
                    v-model="gen.time"
                    mask="99:99"
                    fluid
                    @blur="onTimeBlur"
                />
            </FormField>

            <FormField
                :label="t('appointment_bulk.generator.count')"
                :hint="t('appointment_bulk.generator.count_hint')"
            >
                <InputNumber
                    v-model="gen.count"
                    :min="1"
                    :max="12"
                    show-buttons
                    :use-grouping="false"
                    fluid
                />
            </FormField>

            <FormField :label="t('appointment_bulk.generator.interval')">
                <Select
                    v-model="gen.interval"
                    :options="intervalOptions"
                    option-label="label"
                    option-value="value"
                    fluid
                />
            </FormField>

            <FormField
                v-if="typeOptions.length"
                :label="t('appointment.fields.appointment_type')"
                :hint="t('appointment_bulk.generator.seed_hint')"
            >
                <AppointmentTypeSelect
                    v-model="gen.appointment_type_id"
                    :options="typeOptions"
                />
            </FormField>

            <FormField
                :label="t('appointment.fields.duration_minutes')"
                :hint="t('appointment_bulk.generator.seed_hint')"
            >
                <InputNumber
                    v-model="gen.duration_minutes"
                    :min="5"
                    :max="480"
                    :step="5"
                    suffix=" dk"
                    show-buttons
                    :use-grouping="false"
                    fluid
                />
            </FormField>
        </div>

        <p class="text-xs text-surface-400">
            {{ t('appointment_bulk.generator.regenerated_hint') }}
        </p>

        <!-- List-level error must render even when the generated list is empty (invalid start). -->
        <small v-if="occurrencesError" class="text-xs text-red-500">
            {{ occurrencesError }}
        </small>

        <div v-if="form.occurrences.length" class="flex flex-col gap-3">
            <h3 class="text-sm font-semibold text-surface-700">
                {{ t('appointment_bulk.occurrences_title') }}
            </h3>

            <div class="grid grid-cols-1 gap-3">
                <BulkOccurrenceRow
                    v-for="(occurrence, index) in form.occurrences"
                    :key="index"
                    :index="index"
                    :doctor-id="doctorId"
                    :type-options="typeOptions"
                    :removable="form.occurrences.length > 1"
                    @remove="removeOccurrence(index)"
                />
            </div>
        </div>

        <div>
            <Button
                type="button"
                severity="secondary"
                outlined
                size="small"
                :disabled="!canAddRow"
                :label="t('appointment_bulk.add_row')"
                @click="addRow"
            >
                <template #icon>
                    <IconPlus class="size-4" />
                </template>
            </Button>
        </div>
    </div>
</template>
