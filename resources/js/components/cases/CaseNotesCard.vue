<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconNotes, IconPencil } from '@tabler/icons-vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { update as updateNotes } from '@/routes/cases/notes';
import type { CaseDetail, CaseNotesFormData } from '@/types/case';

const props = defineProps<{ caseRecord: CaseDetail; canManage: boolean }>();

const { t } = useI18n();

const editingNotes = ref(false);
const notesForm = useForm<CaseNotesFormData>({
    notes: props.caseRecord.notes ?? '',
});

function startEditNotes(): void {
    notesForm.clearErrors();
    notesForm.notes = props.caseRecord.notes ?? '';
    editingNotes.value = true;
}

function saveNotes(): void {
    notesForm.patch(updateNotes(props.caseRecord.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            editingNotes.value = false;
        },
    });
}
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
    >
        <header class="flex items-center gap-2">
            <IconNotes class="size-5 text-surface-500" />
            <h3 class="text-sm font-semibold text-surface-900">
                {{ t('case.sections.notes') }}
            </h3>
            <Button
                v-if="canManage && !editingNotes"
                type="button"
                severity="secondary"
                outlined
                size="small"
                class="ml-auto"
                :label="t('case.notes_edit')"
                @click="startEditNotes"
            >
                <template #icon>
                    <IconPencil class="size-4" />
                </template>
            </Button>
        </header>

        <template v-if="editingNotes">
            <Textarea
                v-model="notesForm.notes"
                rows="5"
                auto-resize
                fluid
                :invalid="Boolean(notesForm.errors.notes)"
                :placeholder="t('case.notes_placeholder')"
                :aria-label="t('case.sections.notes')"
            />
            <small v-if="notesForm.errors.notes" class="text-red-500">
                {{ notesForm.errors.notes }}
            </small>
            <div class="flex justify-end gap-2">
                <Button
                    type="button"
                    severity="secondary"
                    outlined
                    size="small"
                    :label="t('common.cancel')"
                    :disabled="notesForm.processing"
                    @click="editingNotes = false"
                />
                <Button
                    type="button"
                    size="small"
                    :label="t('case.save')"
                    :loading="notesForm.processing"
                    @click="saveNotes"
                />
            </div>
        </template>
        <template v-else>
            <p
                v-if="caseRecord.notes"
                class="text-sm whitespace-pre-line text-surface-700"
            >
                {{ caseRecord.notes }}
            </p>
            <p v-else class="text-sm text-surface-400">
                {{ t('case.no_notes') }}
            </p>
        </template>
    </section>
</template>
