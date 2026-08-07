<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import { complete } from '@/routes/follow-ups';
import type { FollowUpCompleteFormData } from '@/types/followUp';

// Completion is a small dialog rather than a confirm: the result note is optional but has to be
// collectable. Shared by the dashboard widget and the case follow-up panel.
const props = defineProps<{
    followUpId: number | null;
    patientName?: string;
}>();

const visible = defineModel<boolean>('visible', { required: true });

const { t } = useI18n();

const form = useForm<FollowUpCompleteFormData>({ result_note: '' });

watch(visible, (open) => {
    if (!open) {
        return;
    }

    form.clearErrors();
    form.result_note = '';
});

function submit(): void {
    if (props.followUpId === null) {
        return;
    }

    form.transform((data) => ({
        result_note: data.result_note.trim() || null,
    })).patch(complete(props.followUpId).url, {
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
        :header="t('follow_up.complete_title')"
        class="w-full max-w-md"
    >
        <form novalidate class="flex flex-col gap-5" @submit.prevent="submit">
            <p class="text-sm text-surface-600">
                {{
                    patientName
                        ? t('follow_up.complete_hint_named', {
                              name: patientName,
                          })
                        : t('follow_up.complete_hint')
                }}
            </p>

            <FormField
                :label="t('follow_up.fields.result_note')"
                :error="form.errors.result_note"
                :hint="t('follow_up.hints.result_note')"
            >
                <Textarea
                    v-model="form.result_note"
                    rows="3"
                    auto-resize
                    fluid
                />
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
                    :label="t('follow_up.actions.complete')"
                    :loading="form.processing"
                />
            </div>
        </form>
    </Dialog>
</template>
