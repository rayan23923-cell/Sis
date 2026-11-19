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
    <div class="card flex flex-col items-start gap-2 p-4 sm:flex-row sm:gap-4 sm:p-5">
        <div v-if="icon" class="grid h-9 w-9 shrink-0 place-items-center rounded-xl sm:h-11 sm:w-11" :class="toneClass[tone]">
            <component :is="icon" class="h-5 w-5 sm:h-6 sm:w-6" />
        </div>
        <div class="min-w-0">
            <p class="text-xs font-medium text-slate-500 sm:text-sm">{{ label }}</p>
            <p class="mt-0.5 text-xl font-extrabold sm:text-2xl tabular-nums text-slate-900">{{ value }}</p>
            <p v-if="hint" class="mt-0.5 text-xs text-slate-500">{{ hint }}</p>
        </div>
    </div>
</template>
