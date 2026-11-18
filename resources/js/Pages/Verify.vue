<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import LocaleSwitch from '@/Components/LocaleSwitch.vue';
import { BookOpenIcon, CheckBadgeIcon, ExclamationTriangleIcon, MagnifyingGlassIcon, XCircleIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ code: String, document: Object });
const input = ref(props.code ?? '');
const check = () => input.value.trim() && router.get(route('verify.form'), { code: input.value.trim() });
</script>

<template>
    <Head title="التحقق من وثيقة" />
    <div class="min-h-screen bg-slate-50">
        <header class="bg-brand-950 text-white">
            <div class="mx-auto flex max-w-3xl items-center gap-3 px-4 py-4">
                <div class="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-brand-400 to-emerald-400"><BookOpenIcon class="h-6 w-6" /></div>
                <div><p class="font-extrabold">التحقق من وثيقة</p><p class="text-xs text-brand-300">{{ $page.props.app.university }}</p></div>
                <LocaleSwitch class="ms-auto rounded-lg px-3 py-1.5 text-sm text-brand-200 hover:bg-white/10" />
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-4 py-8">
            <form class="card flex gap-2 p-4" @submit.prevent="check">
                <input v-model="input" dir="ltr" class="input font-mono uppercase tracking-wider" placeholder="XXXX-XXXX-XXXX" aria-label="رمز الوثيقة" />
                <button class="btn-primary shrink-0"><MagnifyingGlassIcon class="h-4 w-4" /> تحقق</button>
            </form>
            <p v-if="!code" class="mt-3 text-sm text-slate-500">أدخل الرمز المطبوع تحت رمز QR في الوثيقة، أو امسح الرمز بكاميرا الهاتف.</p>

            <div v-if="code && !document" class="card mt-6 flex items-start gap-3 border-rose-200 bg-rose-50 p-5 text-rose-800">
                <XCircleIcon class="h-7 w-7 shrink-0" />
                <div><p class="font-extrabold">لا توجد وثيقة بهذا الرمز</p><p class="mt-1 text-sm">تأكد من كتابة الرمز كما هو مطبوع. وثيقة لا تظهر هنا لم تصدر من النظام.</p></div>
            </div>

            <template v-if="document">
                <div class="card mt-6 flex items-start gap-3 p-5" :class="document.valid ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800'">
                    <CheckBadgeIcon v-if="document.valid" class="h-8 w-8 shrink-0" />
                    <XCircleIcon v-else class="h-8 w-8 shrink-0" />
                    <div>
                        <p class="text-lg font-extrabold">{{ document.valid ? 'وثيقة صحيحة صادرة من النظام' : 'وثيقة ملغاة' }}</p>
                        <p class="mt-1 text-sm">{{ document.type }} · صدرت في <span class="ltr">{{ document.issued_at }}</span></p>
                        <p v-if="!document.valid" class="mt-1 text-sm">أُلغيت في <span class="ltr">{{ document.revoked_at }}</span><span v-if="document.revoke_reason"> — {{ document.revoke_reason }}</span></p>
                    </div>
                </div>
                <div v-if="!document.official" class="mt-3 flex items-center gap-2 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <ExclamationTriangleIcon class="h-5 w-5 shrink-0" /> النتائج في هذه الوثيقة محسوبة بسياسات مؤقتة غير رسمية.
                </div>

                <section class="card mt-6">
                    <div class="card-header"><h2 class="card-title">محتوى الوثيقة</h2></div>
                    <dl class="divide-y divide-slate-100 text-sm">
                        <div class="flex justify-between gap-4 px-5 py-2.5"><dt class="text-slate-500">الاسم</dt><dd class="font-bold">{{ document.data.name }}<span v-if="document.data.name_en" class="ltr ms-2 font-normal text-slate-500">{{ document.data.name_en }}</span></dd></div>
                        <div class="flex justify-between gap-4 px-5 py-2.5"><dt class="text-slate-500">الرقم الجامعي</dt><dd class="ltr font-mono font-bold">{{ document.data.number }}</dd></div>
                        <div class="flex justify-between gap-4 px-5 py-2.5"><dt class="text-slate-500">الكلية</dt><dd>{{ document.data.college }}</dd></div>
                        <div class="flex justify-between gap-4 px-5 py-2.5"><dt class="text-slate-500">القسم / البرنامج</dt><dd>{{ document.data.department }} · {{ document.data.program }}</dd></div>
                        <div v-if="document.data.graduated_on" class="flex justify-between gap-4 px-5 py-2.5"><dt class="text-slate-500">تاريخ التخرج</dt><dd class="ltr">{{ document.data.graduated_on }}</dd></div>
                    </dl>
                    <div v-if="document.data.years?.length" class="overflow-x-auto border-t border-slate-100">
                        <table class="table-base">
                            <thead><tr><th>العام الدراسي</th><th>المواد</th><th>الوحدات المكتسبة</th><th>المعدل</th><th>القرار</th></tr></thead>
                            <tbody>
                                <tr v-for="y in document.data.years" :key="y.year">
                                    <td class="ltr text-start">{{ y.year }}</td><td class="tabular-nums">{{ y.courses }}</td><td class="tabular-nums">{{ y.credits }}</td>
                                    <td class="font-bold tabular-nums">{{ y.gpa ?? '—' }}</td><td>{{ y.decision ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>
        </main>
    </div>
</template>
