<script setup lang="ts">
import { computed } from 'vue';
import FormField from '@/components/FormField.vue';
import SettingRow from '@/components/SettingRow.vue';
import type {
    AnamnesisExtraFormValue,
    AnamnesisFieldDefinition,
} from '@/types/anamnesis';
import { useAnamnesisForm } from './formContext';

// The vertical/clinic-specific half of the anamnesis: one control per seeded field definition,
// written into the form's `extra` bag under the definition's key. Renders nothing when the
// active clinic's vertical has seeded no definitions.
const props = defineProps<{
    fields: AnamnesisFieldDefinition[];
    /** Read-only mode (viewer lacks `anamnesis.update`) — every control is disabled. */
    disabled?: boolean;
}>();

const form = useAnamnesisForm();

/** Definitions arrive in `sort` order; groups follow their first-encounter order. */
const groups = computed(() => {
    const byGroup = new Map<string, AnamnesisFieldDefinition[]>();

    props.fields.forEach((field) => {
        const existing = byGroup.get(field.group);

        if (existing) {
            existing.push(field);

            return;
        }

        byGroup.set(field.group, [field]);
    });

    return [...byGroup.entries()].map(([title, fields]) => ({ title, fields }));
});

const errorFor = (key: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[`extra.${key}`];

// The seed in AnamnesisSection guarantees a type-appropriate value per key; these narrow it
// back for the control's model type.
const stringValue = (key: string): string => {
    const value = form.extra[key];

    return typeof value === 'string' ? value : '';
};

const selectValue = (key: string): string | null => {
    const value = form.extra[key];

    return typeof value === 'string' ? value : null;
};

const numberValue = (key: string): number | null => {
    const value = form.extra[key];

    return typeof value === 'number' ? value : null;
};

const booleanValue = (key: string): boolean => form.extra[key] === true;

const listValue = (key: string): string[] => {
    const value = form.extra[key];

    return Array.isArray(value) ? value : [];
};

const dateValue = (key: string): Date | null => {
    const value = form.extra[key];

    return value instanceof Date ? value : null;
};

function setValue(key: string, value: unknown): void {
    form.extra[key] = (value ?? null) as AnamnesisExtraFormValue;
}
</script>

<template>
    <div v-if="groups.length" class="flex flex-col gap-8">
        <div
            v-for="group in groups"
            :key="group.title"
            class="flex flex-col gap-5"
        >
            <h3 class="text-sm font-semibold text-surface-500">
                {{ group.title }}
            </h3>

            <template v-for="field in group.fields" :key="field.key">
                <SettingRow
                    v-if="field.type === 'boolean'"
                    :label="field.label"
                >
                    <ToggleSwitch
                        :model-value="booleanValue(field.key)"
                        :disabled="disabled"
                        @update:model-value="setValue(field.key, $event)"
                    />
                </SettingRow>

                <FormField
                    v-else
                    :label="field.label"
                    :required="field.required"
                    :error="errorFor(field.key)"
                >
                    <Textarea
                        v-if="field.type === 'textarea'"
                        :model-value="stringValue(field.key)"
                        rows="2"
                        auto-resize
                        :disabled="disabled"
                        fluid
                        @update:model-value="setValue(field.key, $event)"
                    />
                    <Select
                        v-else-if="field.type === 'select'"
                        :model-value="selectValue(field.key)"
                        :options="field.options ?? []"
                        option-label="label"
                        option-value="value"
                        :disabled="disabled"
                        show-clear
                        fluid
                        @update:model-value="setValue(field.key, $event)"
                    />
                    <MultiSelect
                        v-else-if="field.type === 'multiselect'"
                        :model-value="listValue(field.key)"
                        :options="field.options ?? []"
                        option-label="label"
                        option-value="value"
                        :disabled="disabled"
                        :show-toggle-all="false"
                        fluid
                        @update:model-value="setValue(field.key, $event)"
                    />
                    <InputNumber
                        v-else-if="field.type === 'number'"
                        :model-value="numberValue(field.key)"
                        :min-fraction-digits="0"
                        :max-fraction-digits="2"
                        :disabled="disabled"
                        fluid
                        @update:model-value="setValue(field.key, $event)"
                    />
                    <DatePicker
                        v-else-if="field.type === 'date'"
                        :model-value="dateValue(field.key)"
                        date-format="dd.mm.yy"
                        :disabled="disabled"
                        fluid
                        @update:model-value="setValue(field.key, $event)"
                    />
                    <InputText
                        v-else
                        :model-value="stringValue(field.key)"
                        :maxlength="255"
                        :disabled="disabled"
                        fluid
                        @update:model-value="setValue(field.key, $event)"
                    />
                </FormField>
            </template>
        </div>
    </div>
</template>
