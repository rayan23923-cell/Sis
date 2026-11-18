<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({ paginator: { type: Object, required: true } });
const clean = (label) => label.replace('&laquo; Previous', 'السابق').replace('Next &raquo;', 'التالي');
</script>

<template>
    <div v-if="paginator.last_page > 1" class="flex flex-col items-center justify-between gap-3 border-t border-slate-100 px-5 py-3 text-sm sm:flex-row">
        <p class="text-slate-500">
            عرض {{ paginator.from }}–{{ paginator.to }} من أصل <span class="font-bold text-slate-700">{{ paginator.total }}</span>
        </p>
        <div class="flex flex-wrap gap-1">
            <template v-for="(l, i) in paginator.links" :key="i">
                <Link v-if="l.url" :href="l.url" preserve-scroll preserve-state
                    class="min-w-9 rounded-lg px-3 py-1.5 text-center font-semibold transition"
                    :class="l.active ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-100'" v-html="clean(l.label)" />
                <span v-else class="min-w-9 rounded-lg px-3 py-1.5 text-center text-slate-300" v-html="clean(l.label)" />
            </template>
        </div>
    </div>
</template>
