<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { TreatmentLine } from '@/types/treatment';

// The void confirm dialog with its own ConfirmDialog group so the restock checkbox list can live
// in the message slot; the host page owns the selection and posts it as `restock_line_ids`.
// With no product lines the slot renders the warning alone — a plain confirm.
defineProps<{ lines: TreatmentLine[] }>();

const selected = defineModel<number[]>({ required: true });

const { t } = useI18n();
</script>

<template>
    <ConfirmDialog group="treatment-void">
        <template #message="{ message }">
            <div class="flex w-full flex-col gap-4">
                <p class="text-sm text-surface-600">{{ message.message }}</p>

                <div v-if="lines.length" class="flex flex-col gap-2">
                    <span class="text-sm font-medium text-surface-800">
                        {{ t('treatment.void.restock_title') }}
                    </span>
                    <div
                        v-for="line in lines"
                        :key="line.id"
                        class="flex items-center gap-2"
                    >
                        <Checkbox
                            v-model="selected"
                            :value="line.id"
                            :input-id="`restock-line-${line.id}`"
                        />
                        <label
                            :for="`restock-line-${line.id}`"
                            class="text-sm text-surface-700"
                        >
                            {{
                                t('treatment.void.restock_line', {
                                    name: line.name,
                                    quantity: line.quantity,
                                })
                            }}
                        </label>
                    </div>
                    <p class="text-xs text-surface-500">
                        {{ t('treatment.void.restock_hint') }}
                    </p>
                </div>
            </div>
        </template>
    </ConfirmDialog>
</template>
