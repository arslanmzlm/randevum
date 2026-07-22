<script setup lang="ts">
import { IconPaperclip } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import MediaGallery from '@/components/media/MediaGallery.vue';
import SectionCard from '@/components/SectionCard.vue';
import type { CaseMediaItem } from '@/types/media';

// Read-only rollup of media across all the case's treatments — no upload/delete here
// (upload only on the treatment Process screen).
defineProps<{
    items: CaseMediaItem[];
}>();

const { t } = useI18n();
</script>

<template>
    <SectionCard :icon="IconPaperclip" :title="t('media.title')">
        <MediaGallery :items="items" />
    </SectionCard>
</template>
