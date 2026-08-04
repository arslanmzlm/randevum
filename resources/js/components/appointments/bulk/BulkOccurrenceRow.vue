<script setup lang="ts">
import { IconTrash } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppointmentTypeSelect from '@/components/AppointmentTypeSelect.vue';
import AvailabilityBadge from '@/components/AvailabilityBadge.vue';
import FormField from '@/components/FormField.vue';
import { useAvailabilityCheck } from '@/composables/useAvailabilityCheck';
import { clampTime, combineDateTime } from '@/utils/appointmentTime';
import { useBulkAppointmentForm } from './formContext';

const props = defineProps<{
    /** Position of this row in `form.occurrences` — also its label number. */
    index: number;
    doctorId: number | null;
    typeOptions: Array<{ label: string; value: number; color: string }>;
    /** Removal is blocked below the minimum of 1 row. */
    removable: boolean;
}>();

const emit = defineEmits<{ remove: [] }>();

const { t } = useI18n();

const form = useBulkAppointmentForm();

const minDate = new Date();

const occurrence = computed(() => form.occurrences[props.index]);

// Nested dotted error keys aren't part of the form's typed top-level error map.
const fieldError = (key: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[key];

const rowError = computed<string | undefined>(
    () =>
        fieldError(`occurrences.${props.index}.starts_at`) ??
        fieldError(`occurrences.${props.index}`),
);

const typeError = computed<string | undefined>(() =>
    fieldError(`occurrences.${props.index}.appointment_type_id`),
);

const durationError = computed<string | undefined>(() =>
    fieldError(`occurrences.${props.index}.duration_minutes`),
);

// The row error renders exactly once — under the date when it's the empty side, else the time.
const dateError = computed<string | undefined>(() =>
    rowError.value && !occurrence.value?.date ? rowError.value : undefined,
);

const timeError = computed<string | undefined>(() =>
    rowError.value && !dateError.value ? rowError.value : undefined,
);

function onTimeBlur(): void {
    const occ = occurrence.value;

    if (occ) {
        occ.time = clampTime(occ.time);
    }
}

// Per-row probe: its own composable instance gives independent debounce/abort so editing one row
// never re-checks the others. Advisory — the server runs the full 3-layer check + skip per slot.
const { state: availabilityState, reason: availabilityReason } =
    useAvailabilityCheck(() => {
        const occ = occurrence.value;

        if (!occ || props.doctorId === null) {
            return null;
        }

        const startsAt = combineDateTime(occ.date, occ.time);

        if (!startsAt) {
            return null;
        }

        return {
            doctor_id: props.doctorId,
            starts_at: startsAt,
            duration_minutes: occ.duration_minutes,
            service_id: form.service_id,
            appointment_type_id: occ.appointment_type_id,
            is_walk_in: false,
        };
    });
</script>

<template>
    <div
        v-if="occurrence"
        class="flex flex-col gap-3 rounded-lg border border-surface-200 bg-surface-50 p-4"
    >
        <div class="flex items-center justify-between gap-2">
            <span class="text-sm font-medium text-surface-700">
                {{ t('appointment_bulk.occurrence_label', { n: index + 1 }) }}
            </span>
            <!-- Full-size hit area: at `size="small"` with a 16px glyph this was easy to miss. -->
            <Button
                v-if="removable"
                v-tooltip.top="t('appointment_bulk.remove_row')"
                text
                rounded
                severity="danger"
                :aria-label="t('appointment_bulk.remove_row')"
                @click="emit('remove')"
            >
                <template #icon>
                    <IconTrash class="size-5" />
                </template>
            </Button>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <FormField
                :label="t('appointment.fields.date')"
                :error="dateError"
                required
            >
                <DatePicker
                    v-model="occurrence.date"
                    :min-date="minDate"
                    date-format="dd.mm.yy"
                    show-icon
                    fluid
                />
            </FormField>
            <FormField
                :label="t('appointment.fields.time')"
                :error="timeError"
                required
            >
                <InputMask
                    v-model="occurrence.time"
                    mask="99:99"
                    fluid
                    @blur="onTimeBlur"
                />
            </FormField>
            <FormField
                v-if="typeOptions.length"
                :label="t('appointment.fields.appointment_type')"
                :error="typeError"
            >
                <AppointmentTypeSelect
                    v-model="occurrence.appointment_type_id"
                    :options="typeOptions"
                />
            </FormField>
            <FormField
                :label="t('appointment.fields.duration_minutes')"
                :error="durationError"
            >
                <InputNumber
                    v-model="occurrence.duration_minutes"
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

        <AvailabilityBadge
            v-if="!rowError"
            :state="availabilityState"
            :reason="availabilityReason"
        />
    </div>
</template>
