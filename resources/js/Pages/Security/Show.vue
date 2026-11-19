<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { CheckBadgeIcon, ClipboardDocumentIcon, ComputerDesktopIcon, DevicePhoneMobileIcon, ExclamationTriangleIcon, KeyIcon, LockClosedIcon, ShieldCheckIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ twoFactor: Object, recoveryCodes: Array, sessions: Array, logins: Array, passwordChangedAt: String, passwordMin: Number });
const page = usePage();
const Layout = computed(() => (page.props.auth.user.roles?.length ? AppLayout : PortalLayout));

const confirm = useForm({ code: '' });
const begin = () => router.post(route('security.two-factor.begin'), {}, { preserveScroll: true });
const doConfirm = () => confirm.post(route('security.two-factor.confirm'), { preserveScroll: true, onSuccess: () => confirm.reset() });

// actions that need the password again
const ask = ref(null); // 'disable' | 'codes' | 'sessions'
const pw = useForm({ password: '' });
const titles = { disable: 'إيقاف التحقق بخطوتين', codes: 'رموز استرداد جديدة', sessions: 'إنهاء الجلسات الأخرى' };
const runAsk = () => {
    const opts = { preserveScroll: true, onSuccess: () => { ask.value = null; pw.reset(); } };
    if (ask.value === 'disable') pw.delete(route('security.two-factor.disable'), opts);
    if (ask.value === 'codes') pw.post(route('security.recovery-codes'), opts);
    if (ask.value === 'sessions') pw.delete(route('security.sessions.destroy'), opts);
};
const copied = ref(false);
const copy = async () => { try { await navigator.clipboard.writeText(props.recoveryCodes.join('\n')); copied.value = true; setTimeout(() => (copied.value = false), 2000); } catch { /* clipboard blocked: the codes stay on screen */ } };
const tone = { success: 'emerald', logout: 'slate', failed: 'rose', two_factor_failed: 'rose', lockout: 'amber' };
</script>

<template>
    <component :is="Layout" title="الأمان">
        <PageHeader title="الأمان" subtitle="التحقق بخطوتين والأجهزة المتصلة بحسابك وآخر عمليات الدخول." />

        <div v-if="twoFactor.required && !twoFactor.enabled" class="mb-5 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <ExclamationTriangleIcon class="mt-0.5 h-5 w-5 shrink-0" />
            <span>دورك في النظام يتطلب التحقق بخطوتين لحماية الدرجات والبيانات المالية. فعّله الآن، ثم تستطيع المتابعة.</span>
        </div>

        <div class="grid gap-6 lg:grid-cols-5">
            <section class="card p-6 lg:col-span-3">
                <div class="flex items-start gap-4">
                    <div class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl" :class="twoFactor.enabled ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500'">
                        <ShieldCheckIcon class="h-6 w-6" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-extrabold text-slate-900">التحقق بخطوتين</h2>
                            <Badge :tone="twoFactor.enabled ? 'emerald' : 'slate'">{{ twoFactor.enabled ? 'مفعّل' : 'غير مفعّل' }}</Badge>
                            <Badge v-if="twoFactor.required" tone="amber">إلزامي لدورك</Badge>
                        </div>
                        <p class="mt-1 text-sm text-slate-500">بعد كلمة المرور يُطلب رمز من تطبيق في هاتفك (Google Authenticator أو Microsoft Authenticator). يعمل بدون إنترنت.</p>
                    </div>
                </div>

                <div v-if="recoveryCodes?.length" class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50/60 p-5">
                    <p class="flex items-center gap-2 font-bold text-emerald-900"><KeyIcon class="h-5 w-5" /> رموز الاسترداد</p>
                    <p class="mt-1 text-sm text-emerald-800">احفظها في مكان آمن الآن، فلن تظهر مرة أخرى. كل رمز يُدخلك مرة واحدة إذا فقدت الهاتف.</p>
                    <div class="mt-4 grid grid-cols-2 gap-2 font-mono text-sm sm:grid-cols-4" dir="ltr">
                        <span v-for="c in recoveryCodes" :key="c" class="rounded-lg bg-white px-3 py-2 text-center shadow-sm">{{ c }}</span>
                    </div>
                    <button type="button" class="btn-secondary mt-4" @click="copy"><ClipboardDocumentIcon class="h-4 w-4" /> {{ copied ? 'نُسخت' : 'نسخ الرموز' }}</button>
                </div>

                <div v-if="!twoFactor.enabled && !twoFactor.pending" class="mt-6">
                    <button class="btn-primary" @click="begin"><DevicePhoneMobileIcon class="h-4 w-4" /> بدء التفعيل</button>
                </div>

                <div v-if="twoFactor.pending" class="mt-6 grid gap-6 sm:grid-cols-[auto_1fr]">
                    <div class="mx-auto rounded-2xl border border-slate-200 bg-white p-3 [&_svg]:h-44 [&_svg]:w-44" v-html="twoFactor.qr" />
                    <div class="space-y-4">
                        <ol class="list-inside list-decimal space-y-1 text-sm text-slate-600">
                            <li>ثبّت تطبيق مصادقة على هاتفك.</li>
                            <li>امسح الرمز، أو أدخل المفتاح يدوياً.</li>
                            <li>أدخل الرمز الذي يظهر في التطبيق.</li>
                        </ol>
                        <p class="rounded-xl bg-slate-50 px-3 py-2 text-center font-mono text-sm tracking-wider text-slate-700" dir="ltr">{{ twoFactor.secret }}</p>
                        <form class="flex gap-2" @submit.prevent="doConfirm">
                            <input v-model="confirm.code" dir="ltr" inputmode="numeric" maxlength="7" autocomplete="one-time-code" class="input text-center font-mono text-lg tracking-[0.3em]" placeholder="000000" />
                            <button class="btn-primary shrink-0" :disabled="confirm.processing || confirm.code.length < 6">تأكيد</button>
                        </form>
                        <InputError :message="confirm.errors.code" />
                    </div>
                </div>

                <div v-if="twoFactor.enabled" class="mt-6 flex flex-wrap items-center gap-2">
                    <span class="me-auto text-sm text-slate-500">رموز الاسترداد المتبقية: <b class="text-slate-800">{{ twoFactor.recovery_left }}</b></span>
                    <button class="btn-secondary" @click="ask = 'codes'"><KeyIcon class="h-4 w-4" /> رموز جديدة</button>
                    <button v-if="!twoFactor.required" class="btn-secondary text-rose-700" @click="ask = 'disable'">إيقاف</button>
                </div>
            </section>

            <section class="card p-6 lg:col-span-2">
                <div class="flex items-center gap-2">
                    <LockClosedIcon class="h-5 w-5 text-slate-500" />
                    <h2 class="font-extrabold text-slate-900">كلمة المرور</h2>
                </div>
                <p class="mt-2 text-sm text-slate-500">{{ passwordMin }} أحرف على الأقل، فيها حروف وأرقام. تغييرها يُخرج حسابك من الأجهزة الأخرى.</p>
                <p v-if="passwordChangedAt" class="mt-2 text-sm text-slate-500">آخر تغيير: <span class="ltr tabular-nums">{{ passwordChangedAt }}</span></p>
                <a v-if="page.props.auth.user.roles?.length" :href="route('profile.edit')" class="btn-secondary mt-4">تغيير كلمة المرور</a>
            </section>

            <section class="card p-6 lg:col-span-3">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="font-extrabold text-slate-900">الأجهزة المتصلة</h2>
                    <button v-if="sessions.length > 1" class="btn-secondary" @click="ask = 'sessions'">إنهاء الجلسات الأخرى</button>
                </div>
                <ul class="mt-4 divide-y divide-slate-100">
                    <li v-for="s in sessions" :key="s.id" class="flex items-center gap-3 py-3">
                        <component :is="/Android|iOS/.test(s.agent) ? DevicePhoneMobileIcon : ComputerDesktopIcon" class="h-6 w-6 shrink-0 text-slate-400" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-slate-800">{{ s.agent }}</p>
                            <p class="text-xs text-slate-400"><span class="ltr">{{ s.ip }}</span> · <span class="ltr tabular-nums">{{ s.last_active }}</span></p>
                        </div>
                        <Badge v-if="s.current" tone="emerald"><CheckBadgeIcon class="h-3.5 w-3.5" /> هذا الجهاز</Badge>
                    </li>
                </ul>
            </section>

            <section class="card p-6 lg:col-span-2">
                <h2 class="font-extrabold text-slate-900">آخر عمليات الدخول</h2>
                <ul class="mt-4 space-y-3">
                    <li v-for="(l, i) in logins" :key="i" class="flex items-start gap-2 text-sm">
                        <Badge :tone="tone[l.event] ?? 'slate'" class="shrink-0">{{ l.label }}</Badge>
                        <span class="min-w-0 flex-1 text-xs text-slate-500">{{ l.device }} · <span class="ltr">{{ l.ip }}</span><br /><span class="ltr tabular-nums">{{ l.at }}</span></span>
                    </li>
                    <li v-if="!logins.length" class="text-sm text-slate-400">لا توجد عمليات بعد.</li>
                </ul>
            </section>
        </div>

        <Modal :show="!!ask" max-width="md" @close="ask = null">
            <form class="p-6" @submit.prevent="runAsk">
                <h3 class="text-lg font-extrabold">{{ titles[ask] }}</h3>
                <p class="mt-1 text-sm text-slate-500">أدخل كلمة المرور للتأكيد.</p>
                <input v-model="pw.password" type="password" dir="ltr" class="input mt-4" autocomplete="current-password" autofocus required />
                <InputError :message="pw.errors.password" class="mt-1" />
                <div class="mt-6 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="ask = null">إلغاء</button><button class="btn-primary" :disabled="pw.processing">تأكيد</button></div>
            </form>
        </Modal>
    </component>
</template>
