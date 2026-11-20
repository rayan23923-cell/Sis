<script setup>
import { reactive, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import { ArrowDownTrayIcon, CheckIcon, InboxArrowDownIcon, MagnifyingGlassIcon, XMarkIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ rows: Object, filters: Object, types: Object, statuses: Object, counts: Number });
const f = reactive({ status: props.filters.status ?? 'submitted', type: props.filters.type ?? '', q: props.filters.q ?? '' });
const apply = () => router.get(route('requests.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true });
let t;
watch(() => f.q, () => { clearTimeout(t); t = setTimeout(apply, 350); });
watch(() => [f.status, f.type], apply);

const busy = ref(null);
const issue = (r) => { busy.value = r.id; router.post(route('requests.issue', r.id), {}, { preserveScroll: true, onFinish: () => (busy.value = null) }); };
const deliver = (r) => router.post(route('requests.deliver', r.id), {}, { preserveScroll: true });
const rejecting = ref(null);
const rej = useForm({ reason: '' });
const reject = () => rej.post(route('requests.reject', rejecting.value.id), { preserveScroll: true, onSuccess: () => { rejecting.value = null; rej.reset(); } });
const tone = { submitted: 'amber', ready: 'emerald', delivered: 'slate', rejected: 'rose', cancelled: 'slate' };
const tabs = ['submitted', 'ready', 'delivered', 'rejected'];
</script>

<template>
    <AppLayout title="طلبات الوثائق">
        <PageHeader title="طلبات الوثائق" subtitle="ما يطلبه الطلبة من البوابة: كشف درجات، تأييد استمرار، وثيقة تخرج. الإصدار يولّد وثيقة برمز QR ويُبلغ الطالب." />

        <div class="card mb-4 space-y-3 p-4">
            <div class="flex max-w-full gap-1 overflow-x-auto rounded-xl bg-slate-100 p-1 text-sm font-semibold sm:w-fit">
                <button v-for="s in tabs" :key="s" type="button" class="flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg px-4 py-1.5 transition" :class="f.status === s ? 'bg-white text-brand-800 shadow-sm' : 'text-slate-500'" @click="f.status = s">
                    {{ statuses[s] }}<span v-if="s === 'submitted' && counts" class="rounded-full bg-brand-600 px-1.5 text-[11px] text-white">{{ counts }}</span>
                </button>
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="relative sm:col-span-2">
                    <MagnifyingGlassIcon class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input v-model="f.q" class="input ps-9" placeholder="اسم الطالب أو رقمه الجامعي" />
                </div>
                <select v-model="f.type" class="input" aria-label="النوع"><option value="">كل الأنواع</option><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select>
            </div>
        </div>

        <section class="card">
            <EmptyState v-if="!rows.data.length" :icon="InboxArrowDownIcon" :title="f.status === 'submitted' ? 'لا توجد طلبات بانتظارك' : 'لا توجد طلبات'" />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="r in rows.data" :key="r.id" class="flex flex-wrap items-start gap-4 px-5 py-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Link :href="route('students.show', r.student_id)" class="font-bold text-slate-800 hover:text-brand-700">{{ r.name }}</Link>
                            <span class="font-mono text-xs text-slate-400">{{ r.number }}</span>
                            <Badge :tone="tone[r.status]">{{ r.type_label }}</Badge>
                        </div>
                        <p class="mt-1 text-sm text-slate-500">{{ r.program }}<template v-if="r.stage"> · المرحلة {{ r.stage }}</template></p>
                        <p v-if="r.addressed_to || r.purpose" class="mt-1 text-sm text-slate-600"><template v-if="r.addressed_to">إلى: <b>{{ r.addressed_to }}</b></template><template v-if="r.purpose"> · الغرض: {{ r.purpose }}</template></p>
                        <p v-if="r.notes" class="mt-1 text-xs text-slate-500">ملاحظة الطالب: {{ r.notes }}</p>
                        <p v-if="r.response" class="mt-1 text-xs" :class="r.status === 'rejected' ? 'text-rose-600' : 'text-slate-500'">{{ r.response }}</p>
                        <p class="mt-1 text-[11px] text-slate-400">طُلب <span class="ltr tabular-nums">{{ r.created_at }}</span><template v-if="r.handler"> · {{ r.handler }} <span class="ltr tabular-nums">{{ r.handled_at }}</span></template><template v-if="r.code"> · <span class="ltr font-mono">{{ r.code }}</span></template></p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                        <template v-if="r.status === 'submitted'">
                            <button class="btn-secondary text-rose-700" @click="rejecting = r"><XMarkIcon class="h-4 w-4" /> رفض</button>
                            <button class="btn-primary" :disabled="busy === r.id" @click="issue(r)"><CheckIcon class="h-4 w-4" /> إصدار</button>
                        </template>
                        <template v-if="r.status === 'ready' || r.status === 'delivered'">
                            <a :href="route('requests.file', r.id)" target="_blank" class="btn-secondary"><ArrowDownTrayIcon class="h-4 w-4" /> PDF</a>
                            <button v-if="r.status === 'ready'" class="btn-secondary" @click="deliver(r)">سُلّمت ورقياً</button>
                        </template>
                    </div>
                </li>
            </ul>
            <div v-if="rows.last_page > 1" class="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-sm text-slate-500">
                <span>{{ rows.total }} طلب · صفحة {{ rows.current_page }} من {{ rows.last_page }}</span>
                <div class="flex gap-2">
                    <button class="btn-secondary" :disabled="!rows.prev_page_url" @click="router.visit(rows.prev_page_url, { preserveState: true })">السابق</button>
                    <button class="btn-secondary" :disabled="!rows.next_page_url" @click="router.visit(rows.next_page_url, { preserveState: true })">التالي</button>
                </div>
            </div>
        </section>

        <Modal :show="!!rejecting" max-width="md" @close="rejecting = null">
            <form class="p-6" @submit.prevent="reject">
                <h3 class="text-lg font-extrabold">رفض الطلب</h3>
                <p class="mt-1 text-sm text-slate-500">يصل السبب إلى الطالب في البوابة.</p>
                <textarea v-model="rej.reason" rows="3" class="input mt-4" required placeholder="مثال: توجد رسوم غير مسددة" />
                <InputError :message="rej.errors.reason" class="mt-1" />
                <div class="mt-6 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="rejecting = null">إلغاء</button><button class="btn-primary">رفض الطلب</button></div>
            </form>
        </Modal>
    </AppLayout>
</template>
