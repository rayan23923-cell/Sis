<script setup>
import { usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { CheckCircleIcon, ExclamationTriangleIcon, XMarkIcon } from '@heroicons/vue/24/outline';

const page = usePage();
const items = ref([]);
let seq = 0;

const push = (type, text, problems = null) => {
    const id = ++seq;
    items.value.push({ id, type, text, problems });
    setTimeout(() => dismiss(id), type === 'error' ? 12000 : 5000);
};
const dismiss = (id) => (items.value = items.value.filter((i) => i.id !== id));

watch(
    () => page.props.flash,
    (f) => {
        if (f?.success) push('success', f.success);
        if (f?.error) push('error', f.error, f.problems ? Object.values(f.problems) : null);
    },
    { immediate: true, deep: true },
);
watch(
    () => page.props.errors,
    (e) => {
        const keys = Object.keys(e ?? {});
        if (keys.length) push('error', keys.length === 1 ? e[keys[0]] : `يرجى تصحيح ${keys.length} حقول.`);
    },
);
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 top-4 z-[60] flex flex-col items-center gap-2 px-4">
        <TransitionGroup enter-from-class="-translate-y-3 opacity-0" leave-to-class="opacity-0" enter-active-class="transition duration-200" leave-active-class="transition duration-200">
            <div v-for="i in items" :key="i.id"
                class="pointer-events-auto flex w-full max-w-lg items-start gap-3 rounded-2xl border px-4 py-3 shadow-lg"
                :class="i.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900'">
                <CheckCircleIcon v-if="i.type === 'success'" class="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" />
                <ExclamationTriangleIcon v-else class="mt-0.5 h-5 w-5 shrink-0 text-rose-600" />
                <div class="min-w-0 flex-1 text-sm font-semibold">
                    {{ i.text }}
                    <ul v-if="i.problems?.length" class="mt-1 list-inside list-disc text-xs font-normal">
                        <li v-for="(p, k) in i.problems.slice(0, 5)" :key="k">{{ p }}</li>
                    </ul>
                </div>
                <button class="text-current opacity-50 hover:opacity-100" @click="dismiss(i.id)"><XMarkIcon class="h-4 w-4" /></button>
            </div>
        </TransitionGroup>
    </div>
</template>
