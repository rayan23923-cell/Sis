<script setup>
import { computed } from 'vue';

// The week as columns (Saturday first, right-to-left) and the day as rows; each class is a block placed by its times.
const props = defineProps({
    slots: { type: Array, default: () => [] },
    days: { type: Object, required: true },
    editable: Boolean,
    showProgram: Boolean,
});
const emit = defineEmits(['add', 'select']);

const toMin = (t) => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };
const range = computed(() => {
    let from = 8 * 60, to = 15 * 60;
    for (const s of props.slots) { from = Math.min(from, toMin(s.starts_at)); to = Math.max(to, toMin(s.ends_at)); }
    return { from: Math.floor(from / 60) * 60, to: Math.ceil(to / 60) * 60 };
});
const hours = computed(() => { const out = []; for (let m = range.value.from; m < range.value.to; m += 60) out.push(m); return out; });
const PX = 56; // pixels per hour
const height = computed(() => ((range.value.to - range.value.from) / 60) * PX);
const fmt = (m) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;

const palette = ['bg-sky-50 border-sky-300 text-sky-900', 'bg-emerald-50 border-emerald-300 text-emerald-900', 'bg-violet-50 border-violet-300 text-violet-900',
    'bg-amber-50 border-amber-300 text-amber-900', 'bg-rose-50 border-rose-300 text-rose-900', 'bg-teal-50 border-teal-300 text-teal-900',
    'bg-indigo-50 border-indigo-300 text-indigo-900', 'bg-lime-50 border-lime-300 text-lime-900'];
const color = (code) => palette[[...String(code)].reduce((a, c) => a + c.charCodeAt(0), 0) % palette.length];
const style = (s) => ({ top: `${((toMin(s.starts_at) - range.value.from) / 60) * PX}px`, height: `${((toMin(s.ends_at) - toMin(s.starts_at)) / 60) * PX - 3}px` });
const byDay = computed(() => Object.fromEntries(Object.keys(props.days).map((d) => [d, props.slots.filter((s) => String(s.day) === String(d))])));

const clickEmpty = (day, e) => {
    if (!props.editable) return;
    const y = e.offsetY;
    const start = range.value.from + Math.floor(y / (PX / 2)) * 30;
    emit('add', { day: Number(day), starts_at: fmt(start), ends_at: fmt(Math.min(start + 60, 22 * 60)) });
};
</script>

<template>
    <div class="overflow-x-auto">
        <div class="grid min-w-[760px]" :style="{ gridTemplateColumns: `56px repeat(${Object.keys(days).length}, minmax(0, 1fr))` }">
            <div class="sticky top-0 z-10 border-b border-slate-200 bg-white"></div>
            <div v-for="(name, d) in days" :key="'h' + d" class="sticky top-0 z-10 border-b border-s border-slate-200 bg-white py-2 text-center text-sm font-bold text-slate-700">{{ name }}</div>

            <div class="relative" :style="{ height: height + 'px' }">
                <div v-for="h in hours" :key="h" class="absolute inset-x-0 pe-2 text-end text-[11px] tabular-nums text-slate-400" :class="h === range.from ? 'translate-y-0.5' : '-translate-y-2'" :style="{ top: ((h - range.from) / 60) * PX + 'px' }" dir="ltr">{{ fmt(h) }}</div>
            </div>
            <div v-for="(name, d) in days" :key="d" class="relative border-s border-slate-100" :class="editable ? 'cursor-copy' : ''" :style="{ height: height + 'px' }" @click.self="clickEmpty(d, $event)">
                <div v-for="h in hours" :key="h" class="pointer-events-none absolute inset-x-0 border-t border-dashed border-slate-100" :style="{ top: ((h - range.from) / 60) * PX + 'px' }"></div>
                <button v-for="s in byDay[d]" :key="s.id" type="button"
                    class="absolute inset-x-1 overflow-hidden rounded-lg border-s-4 px-2 py-1 text-start text-xs shadow-sm transition hover:shadow-md focus:outline-none focus:ring-2 focus:ring-brand-400"
                    :class="color(s.code)" :style="style(s)" @click="emit('select', s)">
                    <p class="truncate font-bold">{{ s.name }}<span v-if="s.kind === 'lab'" class="font-normal"> · عملي</span></p>
                    <p class="truncate opacity-80" dir="ltr">{{ s.starts_at }}–{{ s.ends_at }}</p>
                    <p v-if="s.room" class="truncate opacity-80">{{ s.room }}</p>
                    <p v-if="s.teacher" class="truncate opacity-70">{{ s.teacher }}</p>
                    <p v-if="showProgram && s.program" class="truncate opacity-70">{{ s.program }} · م{{ s.stage }}/{{ s.section }}</p>
                </button>
            </div>
        </div>
    </div>
</template>
