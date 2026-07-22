<script setup lang="ts">
import { IconPaperclip } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import MediaUploader from '@/components/media/MediaUploader.vue';
import SectionCard from '@/components/SectionCard.vue';
import { useCan } from '@/composables/useCan';
import { destroy, store } from '@/routes/treatments/media';
import type { MediaItem } from '@/types/media';

// Treatment-media upload block on the Process screen. The uploader itself is generic; this
// partial wires it to the treatment endpoints, the accepted-format allowlist (mirrors the
// server FormRequest — jpg/png/webp/heic/heif images + pdf/docx/xlsx docs, no SVG/AV) and the
// 50MB client ceiling. Server re-authorizes + re-validates every upload/delete.
const props = defineProps<{
    treatmentId: number;
    items: MediaItem[];
}>();

const { t } = useI18n();
const { can } = useCan();

const ACCEPT =
    'image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif,application/pdf,.pdf,.docx,.xlsx';
const MAX_SIZE_MB = 50;

function deleteUrlFor(item: MediaItem): string {
    return destroy({ treatment: props.treatmentId, media: item.id }).url;
}
</script>

<template>
    <SectionCard :icon="IconPaperclip" :title="t('media.title')">
        <MediaUploader
            :upload-url="store(treatmentId).url"
            :accept="ACCEPT"
            :max-size-mb="MAX_SIZE_MB"
            :items="items"
            :can-delete="can('treatments.media.delete')"
            :delete-url-for="deleteUrlFor"
        />
    </SectionCard>
</template>
