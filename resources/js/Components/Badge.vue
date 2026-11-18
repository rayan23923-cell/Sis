<script setup>
import { computed } from 'vue';
import { tones } from '@/lib/labels';

const props = defineProps({
    tone: { type: String, default: 'slate' },
    map: { type: Object, default: null },
    value: { type: String, default: null },
    dot: { type: Boolean, default: false },
});
const entry = computed(() => (props.map && props.value ? props.map[props.value] : null));
const toneClass = computed(() => tones[entry.value?.tone ?? props.tone] ?? tones.slate);
</script>

<template>
    <span class="chip" :class="toneClass">
        <span v-if="dot" class="h-1.5 w-1.5 rounded-full bg-current opacity-70" />
        <slot>{{ entry?.label ?? value }}</slot>
    </span>
</template>
