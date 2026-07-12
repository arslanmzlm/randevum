<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconCalendarEvent, IconPencil } from '@tabler/icons-vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useDateTime } from '@/composables/useDateTime';
import { update as updateFollowUp } from '@/routes/cases/follow-up';
import type { CaseDetail, CaseFollowUpFormData } from '@/types/case';
import { parseDateString, toDateString } from '@/utils/datetime';

const props = defineProps<{ caseRecord: CaseDetail; canManage: boolean }>();

const { t } = useI18n();
const { formatDateOnly } = useDateTime();

// Independent of status — a closed case may still carry a reminder.
const editingFollowUp = ref(false);
const followUpForm = useForm<CaseFollowUpFormData>({
    follow_up_date: props.caseRecord.follow_up_date
        ? parseDateString(props.caseRecord.follow_up_date)
        : null,
    follow_up_note: props.caseRecord.follow_up_note ?? '',
});

function startEditFollowUp(): void {
    followUpForm.clearErrors();
    followUpForm.follow_up_date = props.caseRecord.follow_up_date
        ? parseDateString(props.caseRecord.follow_up_date)
        : null;
    followUpForm.follow_up_note = props.caseRecord.follow_up_note ?? '';
    editingFollowUp.value = true;
}

function saveFollowUp(): void {
    followUpForm
        .transform((data) => ({
            follow_up_date: data.follow_up_date
                ? toDateString(data.follow_up_date)
                : null,
            follow_up_note: data.follow_up_note || null,
        }))
        .patch(updateFollowUp(props.caseRecord.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                editingFollowUp.value = false;
            },
        });
}
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
    >
        <header class="flex items-center gap-2">
            <IconCalendarEvent class="size-5 text-surface-500" />
            <h3 class="text-sm font-semibold text-surface-900">
                {{ t('case.sections.follow_up') }}
            </h3>
            <Button
                v-if="canManage && !editingFollowUp"
                type="button"
                severity="secondary"
                outlined
                size="small"
                class="ml-auto"
                :label="t('case.follow_up_edit')"
                @click="startEditFollowUp"
            >
                <template #icon>
                    <IconPencil class="size-4" />
                </template>
            </Button>
        </header>

        <template v-if="editingFollowUp">
            <DatePicker
                v-model="followUpForm.follow_up_date"
                date-format="dd.mm.yy"
                show-button-bar
                fluid
                :invalid="Boolean(followUpForm.errors.follow_up_date)"
                :placeholder="t('case.fields.follow_up_date')"
                :aria-label="t('case.fields.follow_up_date')"
            />
            <small
                v-if="followUpForm.errors.follow_up_date"
                class="text-red-500"
            >
                {{ followUpForm.errors.follow_up_date }}
            </small>
            <Textarea
                v-model="followUpForm.follow_up_note"
                rows="3"
                auto-resize
                fluid
                :invalid="Boolean(followUpForm.errors.follow_up_note)"
                :placeholder="t('case.fields.follow_up_note')"
                :aria-label="t('case.fields.follow_up_note')"
            />
            <div class="flex justify-end gap-2">
                <Button
                    type="button"
                    severity="secondary"
                    outlined
                    size="small"
                    :label="t('common.cancel')"
                    :disabled="followUpForm.processing"
                    @click="editingFollowUp = false"
                />
                <Button
                    type="button"
                    size="small"
                    :label="t('case.save')"
                    :loading="followUpForm.processing"
                    @click="saveFollowUp"
                />
            </div>
        </template>
        <template v-else>
            <div v-if="caseRecord.follow_up_date" class="flex flex-col gap-1">
                <span class="text-sm font-medium text-surface-900">
                    {{ formatDateOnly(caseRecord.follow_up_date) }}
                </span>
                <p
                    v-if="caseRecord.follow_up_note"
                    class="text-sm whitespace-pre-line text-surface-600"
                >
                    {{ caseRecord.follow_up_note }}
                </p>
            </div>
            <p v-else class="text-sm text-surface-400">
                {{ t('case.no_follow_up') }}
            </p>
        </template>
    </section>
</template>
