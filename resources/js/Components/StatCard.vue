<script setup>
defineProps({
    label: String,
    value: [Number, String],
    hint: String,
    icon: [Object, Function],
    tone: { type: String, default: 'brand' },
});
const toneClass = {
    brand: 'bg-brand-50 text-brand-700',
    emerald: 'bg-emerald-50 text-emerald-700',
    amber: 'bg-amber-50 text-amber-700',
    violet: 'bg-violet-50 text-violet-700',
    rose: 'bg-rose-50 text-rose-700',
};
</script>

<template>
    <div class="card flex items-start gap-4 p-5">
        <div v-if="icon" class="grid h-11 w-11 shrink-0 place-items-center rounded-xl" :class="toneClass[tone]">
            <component :is="icon" class="h-6 w-6" />
        </div>
        <div class="min-w-0">
            <p class="text-sm font-medium text-slate-500">{{ label }}</p>
            <p class="mt-0.5 text-2xl font-extrabold tabular-nums text-slate-900">{{ value }}</p>
            <p v-if="hint" class="mt-0.5 text-xs text-slate-500">{{ hint }}</p>
        </div>
    </div>
</template>
