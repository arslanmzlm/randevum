<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { IconPencil, IconStethoscope } from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import CaseStatusTag from '@/components/CaseStatusTag.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useDateTime } from '@/composables/useDateTime';
import { update as updateTitle } from '@/routes/cases/title';
import { show as patientShow } from '@/routes/patients';
import type { CaseDetail, CaseTitleFormData } from '@/types/case';

const props = defineProps<{
    caseRecord: CaseDetail;
    canManage: boolean;
    canEditTitle: boolean;
}>();

const { t } = useI18n();
const { formatDate } = useDateTime();

const editingTitle = ref(false);
const titleForm = useForm<CaseTitleFormData>({ title: props.caseRecord.title });

function startEditTitle(): void {
    titleForm.clearErrors();
    titleForm.title = props.caseRecord.title;
    editingTitle.value = true;
}

function saveTitle(): void {
    titleForm.patch(updateTitle(props.caseRecord.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            editingTitle.value = false;
        },
    });
}

const metaRows = computed(() => {
    const rows = [
        {
            label: t('case.fields.opened_at'),
            value: formatDate(props.caseRecord.opened_at),
        },
    ];

    if (props.caseRecord.suspended_at) {
        rows.push({
            label: t('case.fields.suspended_at'),
            value: formatDate(props.caseRecord.suspended_at),
        });
    }

    if (props.caseRecord.closed_at) {
        rows.push({
            label: t('case.fields.closed_at'),
            value: formatDate(props.caseRecord.closed_at),
        });
    }

    return rows;
});
</script>

<template>
    <SectionCard :icon="IconStethoscope" :title="t('case.sections.info')">
        <template #actions>
            <CaseStatusTag :status="caseRecord.status" />
        </template>

        <div class="flex flex-col gap-5">
            <div class="flex flex-col gap-1">
                <div class="flex items-center gap-2">
                    <span class="text-xs text-surface-500">
                        {{ t('case.fields.title') }}
                    </span>
                    <Button
                        v-if="canManage && canEditTitle && !editingTitle"
                        type="button"
                        severity="secondary"
                        text
                        size="small"
                        class="ml-auto"
                        :label="t('case.edit_title')"
                        @click="startEditTitle"
                    >
                        <template #icon>
                            <IconPencil class="size-4" />
                        </template>
                    </Button>
                </div>

                <template v-if="editingTitle">
                    <InputText
                        v-model="titleForm.title"
                        fluid
                        :invalid="Boolean(titleForm.errors.title)"
                        :aria-label="t('case.fields.title')"
                    />
                    <small v-if="titleForm.errors.title" class="text-red-500">
                        {{ titleForm.errors.title }}
                    </small>
                    <div class="mt-1 flex justify-end gap-2">
                        <Button
                            type="button"
                            severity="secondary"
                            outlined
                            size="small"
                            :label="t('common.cancel')"
                            :disabled="titleForm.processing"
                            @click="editingTitle = false"
                        />
                        <Button
                            type="button"
                            size="small"
                            :label="t('case.save')"
                            :loading="titleForm.processing"
                            @click="saveTitle"
                        />
                    </div>
                </template>
                <p v-else class="text-sm font-medium text-surface-900">
                    {{ caseRecord.title }}
                </p>
            </div>

            <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                <div class="flex flex-col gap-0.5">
                    <dt class="text-xs text-surface-500">
                        {{ t('case.fields.patient') }}
                    </dt>
                    <dd>
                        <Link
                            :href="patientShow(caseRecord.patient.id).url"
                            class="text-sm font-medium text-primary-600 transition-colors hover:text-primary-700"
                        >
                            {{ caseRecord.patient.full_name }}
                        </Link>
                    </dd>
                </div>
                <div class="flex flex-col gap-0.5">
                    <dt class="text-xs text-surface-500">
                        {{ t('case.fields.doctor') }}
                    </dt>
                    <dd class="text-sm text-surface-900">
                        {{ caseRecord.doctor.display_name }}
                    </dd>
                </div>
                <div
                    v-for="(row, idx) in metaRows"
                    :key="idx"
                    class="flex flex-col gap-0.5"
                >
                    <dt class="text-xs text-surface-500">
                        {{ row.label }}
                    </dt>
                    <dd class="text-sm text-surface-900">
                        {{ row.value }}
                    </dd>
                </div>
            </dl>
        </div>
    </SectionCard>
</template>
