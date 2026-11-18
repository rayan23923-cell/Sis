<script setup>
import { Link } from '@inertiajs/vue3';
import { ChevronLeftIcon } from '@heroicons/vue/20/solid';

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    crumbs: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <nav v-if="crumbs.length" class="mb-1.5 flex flex-wrap items-center gap-1 text-xs font-medium text-slate-500">
                <template v-for="(c, i) in crumbs" :key="i">
                    <Link v-if="c.href" :href="c.href" class="hover:text-brand-700">{{ c.label }}</Link>
                    <span v-else>{{ c.label }}</span>
                    <ChevronLeftIcon v-if="i < crumbs.length - 1" class="h-3.5 w-3.5 text-slate-400" />
                </template>
            </nav>
            <h1 class="truncate text-2xl font-extrabold text-slate-900">{{ title }}</h1>
            <p v-if="subtitle" class="mt-1 text-sm text-slate-500">{{ subtitle }}</p>
            <slot name="meta" />
        </div>
        <div class="flex flex-wrap items-center gap-2"><slot /></div>
    </div>
</template>
