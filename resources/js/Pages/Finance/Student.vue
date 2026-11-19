<script setup>
import { ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { BanknotesIcon, DocumentPlusIcon, PrinterIcon, ReceiptPercentIcon, XCircleIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ student: Object, invoices: Array, currentYear: Object, schedule: Object, methods: Object, today: String, perm: Object });
const onlineTone = { pending: 'sky', review: 'amber', failed: 'rose', expired: 'slate' };
const markReviewed = (o) => {
    const note = window.prompt('كيف عولجت الدفعة؟ (مثلاً: أُعيد المبلغ للطالب، أو سُجّل وصل يدوي)');
    if (note) router.post(route('payments.reviewed', o.uuid), { note }, { preserveScroll: true });
};
const page = usePage();

const money = (n) => Number(n ?? 0).toLocaleString('en-US');
const lineTone = { paid: 'emerald', partial: 'sky', due: 'slate', overdue: 'rose', cancelled: 'slate' };
const lineLabel = { paid: 'مسدد', partial: 'مسدد جزئياً', due: 'مستحق', overdue: 'متأخر', cancelled: 'ملغى' };
const statusTone = { open: 'amber', paid: 'emerald', cancelled: 'slate' };
const statusLabel = { open: 'غير مسددة', paid: 'مسددة', cancelled: 'ملغاة' };

// the receipt of the payment just saved, offered for printing
const lastReceipt = ref(null);
watch(() => page.props.flash?.receipt_id, (id) => { if (id) lastReceipt.value = id; }, { immediate: true });

const issue = useForm({ year: props.currentYear?.id });
const doIssue = () => issue.post(route('finance.student.invoice', props.student.id), { preserveScroll: true });

const target = ref(null);
const modal = ref('');
const payForm = useForm({ amount: '', paid_on: props.today, method: 'cash', reference: '', notes: '' });
const discForm = useForm({ discount: 0, reason: '' });
const reasonForm = useForm({ reason: '' });
const open = (kind, item) => {
    target.value = item; modal.value = kind;
    payForm.reset(); payForm.clearErrors(); discForm.clearErrors(); reasonForm.reset(); reasonForm.clearErrors();
    if (kind === 'pay') {
        const next = item.lines.find((l) => l.paid < l.amount);
        payForm.amount = next ? Math.min(next.amount - next.paid, item.balance) : item.balance;
    }
    if (kind === 'discount') { discForm.discount = item.discount; discForm.reason = item.discount_reason ?? ''; }
};
const close = () => { modal.value = ''; };
const opts = { preserveScroll: true, onSuccess: close };
const submit = () => {
    if (modal.value === 'pay') payForm.post(route('finance.pay', target.value.id), opts);
    if (modal.value === 'discount') discForm.post(route('finance.discount', target.value.id), opts);
    if (modal.value === 'cancel') reasonForm.post(route('finance.cancel', target.value.id), opts);
    if (modal.value === 'void') reasonForm.post(route('finance.void', target.value.id), opts);
};
</script>

<template>
    <AppLayout title="رسوم الطالب">
        <PageHeader :title="student.name" :subtitle="`${student.number} · ${student.program ?? ''} · المرحلة ${student.stage ?? '—'} · ${student.channel ?? ''} · ${student.shift}`"
            :crumbs="[{ label: 'الرسوم والأقساط', href: route('finance.index') }, { label: student.number }]">
            <Link v-if="$page.props.can.students" :href="route('students.show', student.id)" class="btn-secondary">ملف الطالب</Link>
        </PageHeader>

        <div v-if="lastReceipt" class="mb-4 flex flex-wrap items-center gap-3 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <BanknotesIcon class="h-5 w-5" /> سُجّلت الدفعة.
            <a :href="route('finance.receipt', lastReceipt)" target="_blank" class="btn-primary ms-auto"><PrinterIcon class="h-4 w-4" /> طباعة الوصل</a>
        </div>

        <div v-if="perm.collect && currentYear && !currentYear.has_invoice" class="card mb-6 flex flex-wrap items-center gap-3 p-5">
            <DocumentPlusIcon class="h-8 w-8 text-brand-600" />
            <div class="min-w-0 flex-1">
                <p class="font-bold text-slate-800">لا توجد مطالبة للعام <span class="ltr">{{ currentYear.name }}</span></p>
                <p v-if="schedule" class="text-sm text-slate-500">تنطبق تعرفة «{{ schedule.name }}»: {{ money(schedule.amount) }} دينار على {{ schedule.installments }} {{ schedule.installments === 1 ? 'دفعة' : 'أقساط' }}.</p>
                <p v-else class="text-sm text-slate-500">لا تنطبق على الطالب أي تعرفة لهذا العام (دراسة مجانية أو لم تُدخل التعرفة بعد).</p>
            </div>
            <button v-if="schedule" class="btn-primary" :disabled="issue.processing" @click="doIssue">إصدار المطالبة</button>
        </div>

        <EmptyState v-if="!invoices.length" class="card" :icon="BanknotesIcon" title="لا توجد مطالبات رسوم لهذا الطالب" />

        <section v-for="inv in invoices" :key="inv.id" class="card mb-6" :class="inv.status === 'cancelled' && 'opacity-70'">
            <div class="card-header flex flex-wrap items-center gap-2">
                <h2 class="card-title">رسوم العام <span class="ltr">{{ inv.year }}</span></h2>
                <Badge :tone="statusTone[inv.status]">{{ statusLabel[inv.status] }}</Badge>
                <span class="text-xs text-slate-400">{{ inv.program }} · المرحلة {{ inv.stage }} · {{ inv.channel }} · {{ inv.shift }}</span>
                <div v-if="perm.collect && inv.status !== 'cancelled'" class="ms-auto flex flex-wrap gap-2">
                    <button v-if="inv.balance > 0" class="btn-primary" @click="open('pay', inv)"><BanknotesIcon class="h-4 w-4" /> تسجيل دفعة</button>
                    <button class="btn-secondary" @click="open('discount', inv)"><ReceiptPercentIcon class="h-4 w-4" /> تخفيض</button>
                    <button v-if="!inv.paid" class="btn-ghost text-rose-600 hover:bg-rose-50" @click="open('cancel', inv)">إلغاء المطالبة</button>
                </div>
            </div>
            <p v-if="inv.cancel_reason" class="border-b border-slate-100 px-5 py-2 text-sm text-slate-500">سبب الإلغاء: {{ inv.cancel_reason }}</p>

            <div class="grid grid-cols-2 divide-x divide-slate-100 border-b border-slate-100 sm:grid-cols-4 rtl:divide-x-reverse">
                <div class="p-4"><p class="text-xs text-slate-500">الرسوم</p><p class="text-lg font-extrabold tabular-nums">{{ money(inv.amount) }}</p></div>
                <div class="p-4"><p class="text-xs text-slate-500">التخفيض</p><p class="text-lg font-extrabold tabular-nums">{{ money(inv.discount) }}</p><p v-if="inv.discount_reason" class="truncate text-xs text-slate-400" :title="inv.discount_reason">{{ inv.discount_reason }}</p></div>
                <div class="p-4"><p class="text-xs text-slate-500">المدفوع</p><p class="text-lg font-extrabold tabular-nums text-emerald-600">{{ money(inv.paid) }}</p></div>
                <div class="p-4"><p class="text-xs text-slate-500">المتبقي</p><p class="text-lg font-extrabold tabular-nums" :class="inv.balance ? 'text-amber-600' : ''">{{ money(inv.balance) }}</p></div>
            </div>

            <div class="grid gap-0 lg:grid-cols-2">
                <div class="border-b border-slate-100 lg:border-b-0 lg:border-e">
                    <p class="px-5 pt-4 text-sm font-bold text-slate-700">الأقساط</p>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="l in inv.lines" :key="l.number" class="flex items-center gap-3 px-5 py-3 text-sm">
                            <span class="grid h-8 w-8 place-items-center rounded-lg bg-slate-100 font-bold text-slate-600">{{ l.number }}</span>
                            <div class="flex-1"><p class="font-bold tabular-nums">{{ money(l.amount) }}</p><p class="text-xs text-slate-400">يستحق <span class="ltr">{{ l.due_on }}</span><span v-if="l.paid && l.paid < l.amount"> · مدفوع {{ money(l.paid) }}</span></p></div>
                            <Badge :tone="lineTone[l.status]">{{ lineLabel[l.status] }}</Badge>
                        </li>
                    </ul>
                </div>
                <div>
                    <p class="px-5 pt-4 text-sm font-bold text-slate-700">الدفعات</p>
                    <p v-if="!inv.payments.length" class="px-5 py-4 text-sm text-slate-400">لا توجد دفعات بعد.</p>
                    <ul v-else class="divide-y divide-slate-100">
                        <li v-for="p in inv.payments" :key="p.id" class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm" :class="p.voided && 'opacity-60'">
                            <div class="min-w-0 flex-1">
                                <p class="font-bold tabular-nums" :class="p.voided && 'line-through'">{{ money(p.amount) }} <span class="font-mono text-xs font-normal text-slate-400">{{ p.receipt }}</span></p>
                                <p class="text-xs text-slate-400"><span class="ltr">{{ p.paid_on }}</span> · {{ p.method }}<span v-if="p.reference"> · <span class="ltr">{{ p.reference }}</span></span><span v-if="p.by"> · {{ p.by }}</span></p>
                                <p v-if="p.voided" class="text-xs text-rose-600">ملغى: {{ p.void_reason }}</p>
                            </div>
                            <a :href="route('finance.receipt', p.id)" target="_blank" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-brand-700" title="طباعة الوصل"><PrinterIcon class="h-4 w-4" /></a>
                            <button v-if="perm.collect && !p.voided" class="rounded-lg p-1.5 text-slate-300 hover:bg-rose-50 hover:text-rose-600" title="إلغاء الوصل" @click="open('void', p)"><XCircleIcon class="h-4 w-4" /></button>
                        </li>
                    </ul>
                    <template v-if="inv.online.length">
                        <p class="px-5 pt-4 text-sm font-bold text-slate-700">محاولات الدفع الإلكتروني</p>
                        <ul class="divide-y divide-slate-100">
                            <li v-for="o in inv.online" :key="o.uuid" class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
                                <div class="min-w-0 flex-1">
                                    <p class="font-bold tabular-nums">{{ money(o.amount) }} <span class="text-xs font-normal text-slate-400">· {{ o.gateway }} · <span class="ltr">{{ o.created_at }}</span></span></p>
                                    <p v-if="o.message" class="text-xs" :class="o.status === 'review' ? 'text-amber-700' : 'text-slate-400'">{{ o.message }}</p>
                                </div>
                                <Badge :tone="onlineTone[o.status]">{{ o.reviewed ? 'روجعت' : o.status_label }}</Badge>
                                <Link :href="route('payments.show', o.uuid)" class="text-xs font-semibold text-brand-700 hover:underline">التفاصيل</Link>
                                <button v-if="perm.collect && o.status === 'review' && !o.reviewed" class="btn-secondary !py-1 text-xs" @click="markReviewed(o)">تمت المعالجة</button>
                            </li>
                        </ul>
                    </template>
                </div>
            </div>
        </section>

        <Modal :show="!!modal" max-width="lg" @close="close">
            <form class="p-6" @submit.prevent="submit">
                <template v-if="modal === 'pay'">
                    <h3 class="text-lg font-extrabold">تسجيل دفعة</h3>
                    <p class="mt-1 text-sm text-slate-500">المتبقي {{ money(target.balance) }} دينار. يصدر وصل قبض مرقم جاهز للطباعة.</p>
                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <div><label class="label">المبلغ (دينار)</label><input v-model.number="payForm.amount" type="number" min="1" :max="target.balance" dir="ltr" class="input tabular-nums" required /><InputError :message="payForm.errors.amount" class="mt-1" /></div>
                        <div><label class="label">تاريخ الدفع</label><input v-model="payForm.paid_on" type="date" :max="today" class="input" required /></div>
                        <div><label class="label">طريقة الدفع</label><select v-model="payForm.method" class="input"><option v-for="(l, k) in methods" :key="k" :value="k">{{ l }}</option></select></div>
                        <div><label class="label">رقم الإيصال المصرفي أو المعاملة</label><input v-model="payForm.reference" dir="ltr" class="input" :required="payForm.method !== 'cash'" /><InputError :message="payForm.errors.reference" class="mt-1" /></div>
                        <div class="sm:col-span-2"><label class="label">ملاحظات</label><input v-model="payForm.notes" class="input" maxlength="255" /></div>
                    </div>
                </template>
                <template v-else-if="modal === 'discount'">
                    <h3 class="text-lg font-extrabold">تخفيض الرسوم</h3>
                    <p class="mt-1 text-sm text-slate-500">مثل تخفيض ذوي الشهداء أو الأوائل حسب ما تقرره الجامعة. يُعاد توزيع الأقساط ويُسجّل السبب في سجل التدقيق.</p>
                    <div class="mt-5 grid gap-4">
                        <div><label class="label">مبلغ التخفيض (دينار) من {{ money(target.amount) }}</label><input v-model.number="discForm.discount" type="number" min="0" :max="target.amount" dir="ltr" class="input tabular-nums" required /><InputError :message="discForm.errors.discount" class="mt-1" /></div>
                        <div><label class="label">السبب والمرجع</label><input v-model="discForm.reason" class="input" required maxlength="255" /><InputError :message="discForm.errors.reason" class="mt-1" /></div>
                    </div>
                </template>
                <template v-else>
                    <h3 class="text-lg font-extrabold">{{ modal === 'void' ? `إلغاء الوصل ${target.receipt}` : 'إلغاء المطالبة' }}</h3>
                    <p class="mt-1 text-sm text-slate-500">{{ modal === 'void' ? 'يبقى الوصل في السجل بعلامة «ملغى» ويُضاف مبلغه إلى المتبقي.' : 'تبقى المطالبة في السجل بعلامة «ملغاة».' }}</p>
                    <div class="mt-5"><label class="label">السبب</label><input v-model="reasonForm.reason" class="input" required maxlength="255" /><InputError :message="reasonForm.errors.reason" class="mt-1" /></div>
                </template>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" class="btn-secondary" @click="close">رجوع</button>
                    <button :class="['void', 'cancel'].includes(modal) ? 'btn-danger' : 'btn-primary'" :disabled="payForm.processing || discForm.processing || reasonForm.processing">{{ modal === 'pay' ? 'حفظ الدفعة' : 'تأكيد' }}</button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
