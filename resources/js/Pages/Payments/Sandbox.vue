<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { BeakerIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ payment: Object });
const form = useForm({ approve: true });
const decide = (approve) => { form.approve = approve; form.post(route('payments.sandbox.decide', props.payment.uuid)); };
const money = (n) => Number(n ?? 0).toLocaleString('en-US');
</script>

<template>
    <Head title="بوابة دفع تجريبية" />
    <div class="grid min-h-screen place-items-center bg-slate-100 px-4">
        <div class="w-full max-w-sm overflow-hidden rounded-3xl bg-white shadow-xl">
            <div class="flex items-center gap-2 bg-slate-900 px-5 py-4 text-white">
                <BeakerIcon class="h-5 w-5 text-amber-300" />
                <p class="font-bold">بوابة دفع تجريبية</p>
            </div>
            <div class="px-6 py-6 text-center">
                <p class="rounded-xl bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">هذه صفحة محاكاة للعرض والاختبار. لا تُخصم أي أموال.</p>
                <p class="mt-5 text-sm text-slate-500">{{ payment.student?.name }} · <span class="ltr">{{ payment.student?.number }}</span></p>
                <p class="mt-1 text-3xl font-extrabold tabular-nums">{{ money(payment.amount) }} <span class="text-base text-slate-500">IQD</span></p>
                <div class="mt-6 grid grid-cols-2 gap-2">
                    <button class="rounded-xl bg-emerald-600 py-3 font-bold text-white hover:bg-emerald-700" :disabled="form.processing" @click="decide(true)">موافقة</button>
                    <button class="rounded-xl bg-slate-100 py-3 font-bold text-slate-700 hover:bg-slate-200" :disabled="form.processing" @click="decide(false)">رفض</button>
                </div>
            </div>
        </div>
    </div>
</template>
