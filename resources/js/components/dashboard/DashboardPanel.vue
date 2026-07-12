<script setup lang="ts">
// Dashboard widgets keep using <DashboardPanel> as their shell; the chrome now lives in the
// shared SectionCard (divided variant) so every card speaks one language. Forwards all slots
// (#title, #actions, default body, #footer) straight through.
import SectionCard from '@/components/SectionCard.vue';
</script>

<template>
    <SectionCard variant="divided">
        <template v-for="(_, name) in $slots" #[name]="slotProps">
            <slot :name="name" v-bind="slotProps ?? {}" />
        </template>
    </SectionCard>
</template>
