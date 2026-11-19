<script setup>
// Marks sent by the exams system (exams connector). The teacher previews them, then accepts or rejects.
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { ArrowDownTrayIcon, ChevronDownIcon, CloudArrowDownIcon, XMarkIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ offeringId: Number, batches: Array, dirty: Boolean });
const open = ref(null);
const busy = ref(false);

const act = (b, action) => {
    let data = {};
    if (action === 'reject') {
        const note = window.prompt('سبب الرفض (اختياري)') ;
        if (note === null) return;
        data = { note };
    }
    busy.value = true;
    router.post(route(`offerings.incoming.${action}`, [props.offeringId, b.id]), data, { preserveScroll: true, onFinish: () => (busy.value = false) });
};
</script>

<template>
    <section v-if="batches.length" class="card mb-6 overflow-hidden border-sky-200">
        <div v-for="b in batches" :key="b.id" class="border-b border-sky-100 last:border-0">
            <div class="flex flex-wrap items-center gap-3 bg-sky-50/70 px-5 py-3">
                <CloudArrowDownIcon class="h-5 w-5 shrink-0 text-sky-700" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-slate-800">درجات واردة من نظام الامتحانات: {{ b.component }}</p>
                    <p class="text-xs text-slate-500">{{ b.count }} طالب · <span class="ltr">{{ b.at }}</span> · لا تدخل السجل إلا بعد اعتمادك<template v-if="b.unknown.length"> · <b class="text-amber-700">{{ b.unknown.length }} رقم جامعي غير موجود في الشعبة</b></template></p>
                </div>
                <button class="btn-ghost" @click="open = open === b.id ? null : b.id"><ChevronDownIcon class="h-4 w-4 transition" :class="open === b.id && 'rotate-180'" /> معاينة</button>
                <template v-if="b.can_review">
                    <button class="btn-secondary" :disabled="busy" @click="act(b, 'reject')"><XMarkIcon class="h-4 w-4" /> رفض</button>
                    <button class="btn-primary" :disabled="busy || dirty" :title="dirty ? 'احفظ تعديلاتك أولاً' : ''" @click="act(b, 'apply')"><ArrowDownTrayIcon class="h-4 w-4" /> إدخال في السجل</button>
                </template>
                <span v-else class="text-xs font-semibold text-slate-500">بانتظار تدريسي المادة</span>
            </div>
            <div v-if="open === b.id" class="max-h-72 overflow-auto">
                <table class="min-w-full text-sm">
                    <thead class="sticky top-0 bg-white text-xs text-slate-500"><tr><th class="px-5 py-2 text-start">الرقم الجامعي</th><th class="px-5 py-2 text-start">الطالب</th><th class="px-5 py-2 text-start">الدرجة من {{ b.max }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="r in b.rows" :key="r.number">
                            <td class="px-5 py-1.5 font-mono text-xs">{{ r.number }}</td>
                            <td class="px-5 py-1.5">{{ r.name }}</td>
                            <td class="px-5 py-1.5 font-bold tabular-nums"><span v-if="r.absent" class="text-rose-600">غائب</span><template v-else>{{ r.mark ?? '—' }}</template></td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="b.unknown.length" class="border-t border-amber-100 bg-amber-50 px-5 py-2 text-xs text-amber-800">أرقام غير موجودة في الشعبة (لم تُستلم): <span class="font-mono ltr">{{ b.unknown.slice(0, 30).join('، ') }}</span></p>
            </div>
        </div>
    </section>
</template>
