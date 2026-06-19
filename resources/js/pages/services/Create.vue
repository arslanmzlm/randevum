<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconClipboardList,
    IconFileText,
} from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import SettingRow from '@/components/SettingRow.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, store } from '@/routes/services';
import type { ServiceCreateProps, ServiceFormData } from '@/types/service';

defineOptions({ layout: AppLayout });

defineProps<ServiceCreateProps>();

const { t } = useI18n();

const form = useForm<ServiceFormData>({
    name: '',
    description: '',
    price: null,
    duration_minutes: null,
    default_complaint: '',
    default_diagnosis: '',
    default_treatment_process: '',
    is_active: true,
});

function submit(): void {
    form.post(store().url);
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('service.create_title')" />

        <PageHeader
            :title="t('service.create_title')"
            :description="t('service.create_subtitle')"
            :breadcrumbs="[
                { label: t('nav.services'), href: index().url },
                { label: t('service.create_title') },
            ]"
        >
            <template #actions>
                <ButtonLink
                    :href="index().url"
                    :label="t('service.back')"
                    severity="secondary"
                    outlined
                >
                    <template #icon>
                        <IconArrowLeft />
                    </template>
                </ButtonLink>
            </template>
        </PageHeader>

        <form novalidate class="flex flex-col gap-6" @submit.prevent="submit">
            <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
                <section
                    class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
                >
                    <header class="mb-6 flex items-center gap-2">
                        <IconClipboardList class="size-5 text-surface-500" />
                        <h2 class="text-lg font-semibold text-surface-900">
                            {{ t('service.sections.info') }}
                        </h2>
                    </header>

                    <div class="flex flex-col gap-5">
                        <FormField
                            :label="t('service.fields.name')"
                            :error="form.errors.name"
                            required
                        >
                            <InputText v-model="form.name" fluid />
                        </FormField>

                        <FormField
                            :label="t('service.fields.price')"
                            :error="form.errors.price"
                            required
                        >
                            <InputNumber
                                v-model="form.price"
                                mode="currency"
                                :currency="currency"
                                :min="0"
                                :max-fraction-digits="2"
                                fluid
                            />
                        </FormField>

                        <FormField
                            :label="t('service.fields.duration_minutes')"
                            :error="form.errors.duration_minutes"
                            :hint="t('service.hints.duration_minutes')"
                        >
                            <InputNumber
                                v-model="form.duration_minutes"
                                suffix=" dk"
                                :min="5"
                                :max="480"
                                :step="5"
                                show-buttons
                                fluid
                            />
                        </FormField>

                        <FormField
                            :label="t('service.fields.description')"
                            :error="form.errors.description"
                        >
                            <Textarea
                                v-model="form.description"
                                rows="3"
                                auto-resize
                                fluid
                            />
                        </FormField>

                        <SettingRow
                            :label="t('service.fields.is_active')"
                            :description="t('service.hints.is_active')"
                        >
                            <ToggleSwitch v-model="form.is_active" />
                        </SettingRow>
                    </div>
                </section>

                <section
                    class="rounded-xl border border-surface-200 bg-surface-0 p-6 sm:p-8"
                >
                    <header class="mb-2 flex items-center gap-2">
                        <IconFileText class="size-5 text-surface-500" />
                        <h2 class="text-lg font-semibold text-surface-900">
                            {{ t('service.sections.templates') }}
                        </h2>
                    </header>
                    <p class="mb-6 text-sm text-surface-500">
                        {{ t('service.hints.templates') }}
                    </p>

                    <div class="flex flex-col gap-5">
                        <FormField
                            :label="t('service.fields.default_complaint')"
                            :error="form.errors.default_complaint"
                        >
                            <Textarea
                                v-model="form.default_complaint"
                                rows="2"
                                auto-resize
                                fluid
                            />
                        </FormField>

                        <FormField
                            :label="t('service.fields.default_diagnosis')"
                            :error="form.errors.default_diagnosis"
                        >
                            <Textarea
                                v-model="form.default_diagnosis"
                                rows="2"
                                auto-resize
                                fluid
                            />
                        </FormField>

                        <FormField
                            :label="
                                t('service.fields.default_treatment_process')
                            "
                            :error="form.errors.default_treatment_process"
                        >
                            <Textarea
                                v-model="form.default_treatment_process"
                                rows="3"
                                auto-resize
                                fluid
                            />
                        </FormField>
                    </div>
                </section>
            </div>

            <div class="flex justify-end">
                <Button
                    type="submit"
                    :label="t('service.create_submit')"
                    :loading="form.processing"
                />
            </div>
        </form>
    </div>
</template>
