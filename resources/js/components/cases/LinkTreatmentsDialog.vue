<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useDateTime } from '@/composables/useDateTime';
import { useMoney } from '@/composables/useMoney';
import { link as linkTreatments } from '@/routes/cases/treatments';
import type { UngroupedTreatmentItem } from '@/types/case';

const props = defineProps<{
    caseId: number;
    ungroupedTreatments: UngroupedTreatmentItem[];
}>();

const visible = defineModel<boolean>('visible', { required: true });

const { t } = useI18n();
const { formatDate } = useDateTime();
const { formatMoney } = useMoney();

const form = useForm<{ treatment_ids: number[] }>({ treatment_ids: [] });

// Reset the selection each time the dialog opens.
watch(visible, (open) => {
    if (!open) {
        return;
    }

    form.clearErrors();
    form.treatment_ids = [];
});

function submit(): void {
    form.post(linkTreatments(props.caseId).url, {
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
        :header="t('case.link.title')"
        :style="{ width: '32rem' }"
    >
        <div class="flex flex-col gap-3">
            <p class="text-sm text-surface-600">
                {{ t('case.link.hint') }}
            </p>
            <ul class="flex flex-col gap-2">
                <li
                    v-for="item in ungroupedTreatments"
                    :key="item.id"
                    class="flex items-center gap-3 rounded-lg border border-surface-200 p-3"
                >
                    <Checkbox
                        v-model="form.treatment_ids"
                        :value="item.id"
                        :input-id="`link-treatment-${item.id}`"
                    />
                    <label
                        :for="`link-treatment-${item.id}`"
                        class="flex min-w-0 flex-1 cursor-pointer flex-col gap-0.5"
                    >
                        <span
                            class="truncate text-sm font-medium text-surface-900"
                        >
                            {{ item.title || t('treatment.untitled') }}
                        </span>
                        <span class="text-xs text-surface-500">
                            {{
                                item.completed_at
                                    ? formatDate(item.completed_at)
                                    : t('treatment.in_progress')
                            }}
                        </span>
                    </label>
                    <span class="text-sm font-medium text-surface-700">
                        {{ formatMoney(item.total_amount) }}
                    </span>
                </li>
            </ul>
            <small v-if="form.errors.treatment_ids" class="text-red-500">
                {{ form.errors.treatment_ids }}
            </small>
        </div>
        <template #footer>
            <Button
                type="button"
                severity="secondary"
                outlined
                :label="t('common.cancel')"
                :disabled="form.processing"
                @click="visible = false"
            />
            <Button
                type="button"
                :label="t('case.link.submit')"
                :disabled="form.treatment_ids.length === 0"
                :loading="form.processing"
                @click="submit"
            />
        </template>
    </Dialog>
</template>
