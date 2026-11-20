<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { BellIcon, CheckCircleIcon, ExclamationTriangleIcon, InformationCircleIcon, XCircleIcon } from '@heroicons/vue/24/outline';

defineProps({ dark: Boolean });
const page = usePage();
const open = ref(false);
const items = ref([]);
const unread = ref(page.props.unread ?? 0);
watch(() => page.props.unread, (n) => (unread.value = n ?? 0));

const load = async () => {
    try {
        const { data } = await axios.get(route('notifications.index'));
        items.value = data.items; unread.value = data.unread;
    } catch { /* offline or signed out: keep what is shown */ }
};
const toggle = () => { open.value = !open.value; if (open.value) load(); };
const readAll = () => router.post(route('notifications.read'), {}, { preserveScroll: true, preserveState: true, onSuccess: () => { items.value = items.value.map((i) => ({ ...i, read: true })); unread.value = 0; } });
const icon = { success: CheckCircleIcon, warning: ExclamationTriangleIcon, danger: XCircleIcon, info: InformationCircleIcon };
const tone = { success: 'text-emerald-600', warning: 'text-amber-600', danger: 'text-rose-600', info: 'text-sky-600' };

// a quiet refresh of the count every two minutes while the page is open
let timer;
onMounted(() => { timer = setInterval(() => { if (!document.hidden) axios.get(route('notifications.index')).then(({ data }) => (unread.value = data.unread)).catch(() => {}); }, 120000); });
onBeforeUnmount(() => clearInterval(timer));
const close = (e) => { if (!e.target.closest?.('[data-bell]')) open.value = false; };
onMounted(() => document.addEventListener('click', close));
onBeforeUnmount(() => document.removeEventListener('click', close));
</script>

<template>
    <div class="relative" data-bell>
        <button type="button" class="relative rounded-full p-2 transition" :class="dark ? 'text-brand-200 hover:bg-white/10 hover:text-white' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-700'" :aria-label="'الإشعارات'" @click="toggle">
            <BellIcon class="h-5 w-5" />
            <span v-if="unread" class="absolute -end-0.5 -top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-brand-600 px-1 text-[10px] font-bold text-white">{{ unread > 9 ? '9+' : unread }}</span>
        </button>
        <Transition enter-from-class="opacity-0 -translate-y-1" leave-to-class="opacity-0" enter-active-class="transition duration-150" leave-active-class="transition duration-100">
            <div v-if="open" class="absolute end-0 z-50 mt-2 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-slate-200 bg-white text-slate-700 shadow-lg">
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                    <p class="font-extrabold text-slate-800">الإشعارات</p>
                    <button v-if="unread" type="button" class="text-xs font-semibold text-brand-700 hover:underline" @click="readAll">تعليم الكل كمقروء</button>
                </div>
                <ul class="max-h-96 divide-y divide-slate-50 overflow-y-auto">
                    <li v-for="n in items" :key="n.id">
                        <a :href="route('notifications.open', n.id)" class="flex gap-3 px-4 py-3 transition hover:bg-slate-50" :class="!n.read && 'bg-brand-50/40'">
                            <component :is="icon[n.kind] ?? InformationCircleIcon" class="mt-0.5 h-5 w-5 shrink-0" :class="tone[n.kind] ?? tone.info" />
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-bold text-slate-800">{{ n.title }}</p>
                                <p class="text-xs text-slate-500">{{ n.body }}</p>
                                <p class="mt-0.5 text-[11px] tabular-nums text-slate-400" dir="ltr">{{ n.at }}</p>
                            </div>
                            <span v-if="!n.read" class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-brand-500"></span>
                        </a>
                    </li>
                    <li v-if="!items.length" class="px-4 py-8 text-center text-sm text-slate-400">لا توجد إشعارات.</li>
                </ul>
            </div>
        </Transition>
    </div>
</template>
