<script setup lang="ts">
import CreatableSelect from '@/components/crud/CreatableSelect.vue';
import { useCan } from '@/composables/useCan';
import { appointmentTypeResource } from '@/crud/appointmentType';
import type { AppointmentType } from '@/types/appointmentType';

type TypeOption = { label: string; value: number; color: string };

withDefaults(
    defineProps<{
        options: TypeOption[];
        /** Offers "add type" inside the dropdown — off where the select repeats per row. */
        creatable?: boolean;
        /** Declared so FormField's cloneVNode injection reaches the inner Select. */
        inputId?: string;
        invalid?: boolean;
    }>(),
    { creatable: false },
);

const model = defineModel<number | null>({ required: true });

const { can } = useCan();

// A type created from the dropdown has to reach the same option shape the page sends.
function toOption(type: AppointmentType): TypeOption {
    return { label: type.name, value: type.id, color: type.color };
}
</script>

<template>
    <CreatableSelect
        v-model="model"
        :options="options"
        option-label="label"
        option-value="value"
        :resource="appointmentTypeResource"
        :to-option="toOption"
        :reload-only="['appointmentTypes']"
        :can-create="creatable && can('appointmentTypes.create')"
        :input-id="inputId"
        :invalid="invalid"
        show-clear
    >
        <template #value="{ option }">
            <span v-if="option" class="flex items-center gap-2">
                <span
                    class="size-3 shrink-0 rounded-full"
                    :style="{ backgroundColor: option.color }"
                    :aria-hidden="true"
                />
                {{ option.label }}
            </span>
            <!-- FloatLabel passes no placeholder; a non-breaking space keeps the empty label
                 the same height as a selected value (variant="in" reserves the floated-label
                 row), matching the sibling selects. -->
            <span v-else>&nbsp;</span>
        </template>

        <template #option="{ option }">
            <span class="flex items-center gap-2">
                <span
                    class="size-3 shrink-0 rounded-full"
                    :style="{ backgroundColor: option.color }"
                    :aria-hidden="true"
                />
                {{ option.label }}
            </span>
        </template>
    </CreatableSelect>
</template>
