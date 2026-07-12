<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { update as updateStatus } from '@/routes/cases/status';
import type { CaseDetail, CaseFollowUpFormData } from '@/types/case';
import { parseDateString, toDateString } from '@/utils/datetime';

const props = defineProps<{ caseRecord: CaseDetail }>();

const visible = defineModel<boolean>('visible', { required: true });

const { t } = useI18n();

const form = useForm<CaseFollowUpFormData>({
    follow_up_date: null,
    follow_up_note: '',
});

// Seed the form from the case each time the dialog opens (the → follow_up transition
// pre-fills any existing reminder), mirroring the original onTransition('follow_up') init.
watch(visible, (open) => {
    if (!open) {
        return;
    }

    form.clearErrors();
    form.follow_up_date = props.caseRecord.follow_up_date
        ? parseDateString(props.caseRecord.follow_up_date)
        : null;
    form.follow_up_note = props.caseRecord.follow_up_note ?? '';
});

function submit(): void {
    form.transform((data) => ({
        status: 'follow_up',
        follow_up_date: data.follow_up_date
            ? toDateString(data.follow_up_date)
            : null,
        follow_up_note: data.follow_up_note || null,
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
        :header="t('case.actions.follow_up')"
        :style="{ width: '28rem' }"
    >
        <div class="flex flex-col gap-4">
            <p class="text-sm text-surface-600">
                {{ t('case.follow_up_status_hint') }}
            </p>
            <div class="flex flex-col gap-1">
                <label class="text-xs text-surface-500">
                    {{ t('case.fields.follow_up_date') }}
                </label>
                <DatePicker
                    v-model="form.follow_up_date"
                    date-format="dd.mm.yy"
                    fluid
                    :invalid="Boolean(form.errors.follow_up_date)"
                    :aria-label="t('case.fields.follow_up_date')"
                />
                <small v-if="form.errors.follow_up_date" class="text-red-500">
                    {{ form.errors.follow_up_date }}
                </small>
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-xs text-surface-500">
                    {{ t('case.fields.follow_up_note') }}
                </label>
                <Textarea
                    v-model="form.follow_up_note"
                    rows="3"
                    auto-resize
                    fluid
                    :aria-label="t('case.fields.follow_up_note')"
                />
            </div>
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
                severity="info"
                :label="t('case.actions.follow_up')"
                :loading="form.processing"
                @click="submit"
            />
        </template>
    </Dialog>
</template>
