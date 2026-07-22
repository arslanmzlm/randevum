<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ColorField from '@/components/ColorField.vue';
import FormField from '@/components/FormField.vue';
import { store, update } from '@/routes/tags';
import type { TagFormData, TagWithCount } from '@/types/tag';
import { COLOR_PRESETS } from '@/utils/colorPresets';

// Shared create/edit dialog for a clinic tag. `tag=null` → create; a tag → edit.
const props = defineProps<{ tag: TagWithCount | null }>();

const visible = defineModel<boolean>('visible', { required: true });

const { t } = useI18n();

const form = useForm<TagFormData>({
    name: '',
    color: COLOR_PRESETS[0],
});

const isEdit = computed(() => props.tag !== null);

// Seed the form from the target each time the dialog opens.
watch(visible, (open) => {
    if (!open) {
        return;
    }

    form.clearErrors();
    form.name = props.tag?.name ?? '';
    form.color = props.tag?.color ?? COLOR_PRESETS[0];
});

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            visible.value = false;
        },
    };

    if (props.tag) {
        form.put(update(props.tag.id).url, options);
    } else {
        form.post(store().url, options);
    }
}
</script>

<template>
    <Dialog
        v-model:visible="visible"
        modal
        :draggable="false"
        :header="isEdit ? t('tag.edit_title') : t('tag.create_title')"
        class="w-full max-w-sm"
    >
        <form novalidate class="flex flex-col gap-5" @submit.prevent="submit">
            <FormField
                :label="t('tag.fields.name')"
                :error="form.errors.name"
                required
            >
                <InputText v-model="form.name" maxlength="50" fluid />
            </FormField>

            <ColorField
                v-model="form.color"
                :label="t('tag.fields.color')"
                :error="form.errors.color"
                :presets="COLOR_PRESETS"
            />

            <div class="mt-1 flex justify-end gap-2">
                <Button
                    type="button"
                    severity="secondary"
                    text
                    :label="t('common.cancel')"
                    @click="visible = false"
                />
                <Button
                    type="submit"
                    :label="isEdit ? t('tag.save') : t('tag.create_submit')"
                    :loading="form.processing"
                />
            </div>
        </form>
    </Dialog>
</template>
