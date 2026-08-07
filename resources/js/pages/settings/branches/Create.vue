<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import SectionCard from '@/components/SectionCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { index, store } from '@/routes/settings/branches';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    verticals: Array<{ id: number; slug: string; name: string }>;
    sourceClinic: { id: number; name: string; vertical_id: number };
}>();

const { t } = useI18n();

const form = useForm({
    name: '',
    vertical_id: props.sourceClinic.vertical_id,
    copy_catalog: false,
});

// Catalog rows carry a vertical_id, so a copy across verticals would import the wrong catalog —
// the server rejects it too; here the checkbox simply goes away.
const canCopyCatalog = computed(
    () => form.vertical_id === props.sourceClinic.vertical_id,
);

function submit(): void {
    form.transform((data) => ({
        ...data,
        copy_catalog: canCopyCatalog.value ? data.copy_catalog : false,
    })).post(store().url);
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <Head :title="t('branch.create_title')" />

        <PageHeader
            :title="t('branch.create_title')"
            :description="t('branch.create_subtitle')"
            :breadcrumbs="[
                { label: t('nav.branches'), href: index().url },
                { label: t('branch.create_title') },
            ]"
        />

        <form novalidate @submit.prevent="submit">
            <SectionCard class="max-w-xl">
                <div class="flex flex-col gap-5">
                    <FormField
                        :label="t('branch.name')"
                        :error="form.errors.name"
                        required
                    >
                        <InputText v-model="form.name" fluid />
                    </FormField>

                    <FormField
                        :label="t('branch.vertical')"
                        :error="form.errors.vertical_id"
                        required
                    >
                        <Select
                            v-model="form.vertical_id"
                            :options="props.verticals"
                            option-label="name"
                            option-value="id"
                            fluid
                        />
                    </FormField>

                    <div class="flex flex-col gap-1.5">
                        <div class="flex items-start gap-2">
                            <Checkbox
                                v-model="form.copy_catalog"
                                input-id="copy_catalog"
                                :binary="true"
                                :disabled="!canCopyCatalog"
                                :invalid="!!form.errors.copy_catalog"
                            />
                            <label
                                for="copy_catalog"
                                class="cursor-pointer text-sm text-surface-600 select-none"
                                :class="canCopyCatalog ? '' : 'opacity-60'"
                            >
                                {{ t('branch.copy_catalog') }}
                            </label>
                        </div>
                        <small
                            v-if="form.errors.copy_catalog"
                            class="text-xs text-red-500"
                        >
                            {{ form.errors.copy_catalog }}
                        </small>
                        <small v-else class="text-xs text-surface-400">
                            {{
                                canCopyCatalog
                                    ? t('branch.copy_catalog_source', {
                                          name: props.sourceClinic.name,
                                      })
                                    : t('branch.copy_catalog_hint')
                            }}
                        </small>
                    </div>
                </div>

                <template #footer>
                    <div class="flex items-center justify-end gap-2">
                        <ButtonLink
                            :href="index().url"
                            severity="secondary"
                            text
                            :label="t('common.cancel')"
                        />
                        <Button
                            type="submit"
                            :label="t('branch.submit')"
                            :loading="form.processing"
                        />
                    </div>
                </template>
            </SectionCard>
        </form>
    </div>
</template>
