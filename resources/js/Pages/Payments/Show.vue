<script setup>
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { ArrowPathIcon, ArrowTopRightOnSquareIcon, CheckCircleIcon, ClockIcon, DevicePhoneMobileIcon, ExclamationTriangleIcon, PrinterIcon, XCircleIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ payment: Object, own: Boolean, statuses: Object });
const page = usePage();
const Layout = computed(() => (props.own ? PortalLayout : AppLayout));
const money = (n) => Number(n ?? 0).toLocaleString('en-US');
const look = {
    pending: { icon: ClockIcon, ring: 'bg-sky-100 text-sky-600', title: 'بانتظار إتمام الدفع' },
    paid: { icon: CheckCircleIcon, ring: 'bg-emerald-100 text-emerald-600', title: 'تم الدفع وصدر الوصل' },
    review: { icon: ExclamationTriangleIcon, ring: 'bg-amber-100 text-amber-600', title: 'وصلت الدفعة وتنتظر مراجعة المحاسب' },
    failed: { icon: XCircleIcon, ring: 'bg-rose-100 text-rose-600', title: 'لم يكتمل الدفع' },
    expired: { icon: XCircleIcon, ring: 'bg-slate-100 text-slate-500', title: 'انتهت مهلة الدفع' },
    cancelled: { icon: XCircleIcon, ring: 'bg-slate-100 text-slate-500', title: 'ألغيت الدفعة' },
};
const s = computed(() => look[props.payment.status] ?? look.failed);

let timer = null;
const poll = async () => {
    try {
        const { data } = await axios.post(route('payments.check', props.payment.uuid), {}, { headers: { Accept: 'application/json' } });
        if (data.status !== props.payment.status) router.reload({ preserveScroll: true });
    } catch { /* try again on the next tick */ }
};
onMounted(() => { if (props.payment.status === 'pending') timer = setInterval(poll, 5000); });
onBeforeUnmount(() => clearInterval(timer));
const checkNow = () => router.post(route('payments.check', props.payment.uuid), {}, { preserveScroll: true });
const cancel = () => confirm('إلغاء هذه الدفعة؟') && router.post(route('payments.cancel', props.payment.uuid));
</script>

<template>
    <component :is="Layout" title="دفع إلكتروني">
        <div class="mx-auto max-w-xl">
            <div class="card overflow-hidden">
                <div class="flex flex-col items-center px-6 pb-6 pt-8 text-center">
                    <div class="grid h-16 w-16 place-items-center rounded-full" :class="s.ring">
                        <component :is="s.icon" class="h-9 w-9" :class="payment.status === 'pending' ? 'animate-pulse' : ''" />
                    </div>
                    <h1 class="mt-4 text-lg font-extrabold text-slate-800">{{ s.title }}</h1>
                    <p class="mt-1 text-3xl font-extrabold tabular-nums text-slate-900">{{ money(payment.amount) }} <span class="text-base font-bold text-slate-500">دينار</span></p>
                    <p class="mt-1 text-sm text-slate-500">عبر {{ payment.gateway }} · رسوم العام <span class="ltr">{{ payment.year }}</span></p>
                    <p v-if="payment.message" class="mt-3 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600">{{ payment.message }}</p>
                </div>

                <!-- paid inside the bank's app (FIB) -->
                <div v-if="payment.display" class="border-t border-slate-100 bg-slate-50/60 px-6 py-6 text-center">
                    <p class="text-sm font-bold text-slate-700">امسح الرمز من تطبيق المصرف</p>
                    <img v-if="payment.display.qr" :src="payment.display.qr" alt="QR" class="mx-auto mt-3 h-52 w-52 rounded-2xl bg-white p-2 shadow-sm" />
                    <p v-if="payment.display.code" class="mt-3 text-xs text-slate-500">أو أدخل الرمز: <span class="ltr font-mono text-base font-bold tracking-widest text-slate-800">{{ payment.display.code }}</span></p>
                    <a v-if="payment.display.app_link" :href="payment.display.app_link" class="btn-primary mt-4 sm:hidden"><DevicePhoneMobileIcon class="h-5 w-5" /> افتح تطبيق المصرف</a>
                </div>

                <div class="flex flex-wrap items-center justify-center gap-2 border-t border-slate-100 px-6 py-4">
                    <a v-if="payment.resume" :href="payment.resume" class="btn-primary"><ArrowTopRightOnSquareIcon class="h-5 w-5" /> أكمل الدفع</a>
                    <button v-if="payment.status === 'pending'" class="btn-secondary" @click="checkNow"><ArrowPathIcon class="h-4 w-4" /> تحقق الآن</button>
                    <a v-if="payment.receipt" :href="route('finance.receipt', payment.receipt.id)" target="_blank" class="btn-primary"><PrinterIcon class="h-5 w-5" /> الوصل <span class="ltr font-mono">{{ payment.receipt.number }}</span></a>
                    <button v-if="own && payment.status === 'pending'" class="btn-ghost text-rose-600" @click="cancel">إلغاء</button>
                    <Link v-if="own" :href="route('portal.home', { tab: 'fees' })" class="btn-ghost">العودة إلى الرسوم</Link>
                    <Link v-else-if="payment.student" :href="route('finance.student', payment.student.id)" class="btn-ghost">حساب الطالب</Link>
                </div>
                <p v-if="payment.status === 'pending'" class="border-t border-slate-100 px-6 py-3 text-center text-xs text-slate-400">تُحدَّث هذه الصفحة تلقائياً عند تأكيد البوابة. لا تدفع مرتين.</p>
            </div>
            <p v-if="payment.reference" class="mt-3 text-center text-xs text-slate-400">رقم المرجع <span class="ltr font-mono">{{ payment.reference }}</span></p>
        </div>
    </component>
</template>
