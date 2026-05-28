<script setup lang="ts">
import { cloneVNode, useId, useSlots } from 'vue';
import type { VNode } from 'vue';

const props = defineProps<{
    label?: string;
    error?: string;
    hint?: string;
    required?: boolean;
}>();

const slots = useSlots();
const fieldId = useId();

// Auto-inject the field id (as `inputId` for PrimeVue controls that wrap an inner
// input, else `id`) and `invalid` into the slotted control — so callers pass neither.
function FieldControl(): VNode[] {
    return (slots.default?.() ?? []).map((vnode) => {
        if (typeof vnode.type !== 'object') {
            return vnode;
        }

        const declared = (
            vnode.type as { props?: string[] | Record<string, unknown> }
        ).props;
        const names = Array.isArray(declared)
            ? declared
            : Object.keys(declared ?? {});
        const idProp = names.includes('inputId') ? 'inputId' : 'id';

        return cloneVNode(vnode, { [idProp]: fieldId, invalid: !!props.error });
    });
}
</script>

<template>
    <div class="form-group">
        <FloatLabel variant="in">
            <FieldControl />
            <label v-if="label" :for="fieldId" class="text-muted">
                {{ label }}<span v-if="required" class="text-red-500"> *</span>
            </label>
        </FloatLabel>
        <small v-if="error" class="text-xs text-red-500">{{ error }}</small>
        <small v-else-if="hint" class="text-xs text-surface-400">{{
            hint
        }}</small>
    </div>
</template>
