<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import { update as updateStatus } from '@/routes/cases/status';
import type { CaseDetail, CaseFollowUpFormData } from '@/types/case';
import type { FollowUpTypeOption } from '@/types/followUp';
import { toDateString } from '@/utils/datetime';

const props = defineProps<{
    caseRecord: CaseDetail;
    followUpTypes: FollowUpTypeOption[];
}>();

const visible = defineModel<boolean>('visible', { required: true });

const { t } = useI18n();

const form = useForm<CaseFollowUpFormData>({
    follow_up_type_id: null,
    due_date: null,
    note: '',
});

// The transition creates a brand-new follow-up row, so the dialog always opens blank.
watch(visible, (open) => {
    if (!open) {
        return;
    }

    form.clearErrors();
    form.reset();
});

function submit(): void {
    form.transform((data) => ({
        status: 'follow_up',
        follow_up_type_id: data.follow_up_type_id,
        due_date: data.due_date ? toDateString(data.due_date) : null,
        note: data.note.trim() || null,
    })).patch(updateStatus(props.caseRecord.id).url, {
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
        :draggable="false"
        :header="t('case.actions.follow_up')"
        class="w-full max-w-md"
    >
        <form novalidate class="flex flex-col gap-5" @submit.prevent="submit">
            <p class="text-sm text-surface-600">
                {{ t('case.follow_up_status_hint') }}
            </p>

            <FormField
                :label="t('follow_up.fields.type')"
                :error="form.errors.follow_up_type_id"
                required
            >
                <Select
                    v-model="form.follow_up_type_id"
                    :options="followUpTypes"
                    option-label="name"
                    option-value="id"
                    :empty-message="t('follow_up.no_types')"
                    fluid
                />
            </FormField>

            <FormField
                :label="t('follow_up.fields.due_date')"
                :error="form.errors.due_date"
                required
            >
                <DatePicker
                    v-model="form.due_date"
                    date-format="dd.mm.yy"
                    show-button-bar
                    fluid
                />
            </FormField>

            <FormField
                :label="t('follow_up.fields.note')"
                :error="form.errors.note"
            >
                <Textarea v-model="form.note" rows="3" auto-resize fluid />
            </FormField>

            <div class="mt-1 flex justify-end gap-2">
                <Button
                    type="button"
                    severity="secondary"
                    text
                    :label="t('common.cancel')"
                    :disabled="form.processing"
                    @click="visible = false"
                />
                <Button
                    type="submit"
                    severity="info"
                    :label="t('case.actions.follow_up')"
                    :loading="form.processing"
                />
            </div>
        </form>
    </Dialog>
</template>
