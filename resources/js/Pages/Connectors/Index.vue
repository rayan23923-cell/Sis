<script setup>
import { ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import { num } from '@/lib/labels';
import {
    ArrowPathIcon, BoltIcon, BookOpenIcon, CheckCircleIcon, ClipboardDocumentCheckIcon, ClipboardDocumentIcon, ExclamationTriangleIcon,
    KeyIcon, LinkIcon, LinkSlashIcon, ShieldCheckIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({ connectors: Array, newKey: { type: Object, default: null }, apiBase: String });

const icons = { library: BookOpenIcon, exams: ClipboardDocumentCheckIcon };
const operations = { members_sync: 'مزامنة القرّاء', clearance: 'براءة الذمة', sync: 'مزامنة', marks_received: 'استلام درجات' };

// connect
const connecting = ref(null);
const form = useForm({ base_url: '', token: '' });
const openConnect = (c) => { connecting.value = c; form.reset(); form.clearErrors(); form.base_url = c.base_url ?? ''; };
const connect = () => form.post(route('connectors.connect', connecting.value.key), { preserveScroll: true, onSuccess: () => (connecting.value = null) });

// tools: saved at once, like a switch in Claude's connector settings
const busy = ref(false);
const toggle = (c, tool) => {
    const on = c.tools.filter((t) => (t.key === tool.key ? !t.on : t.on)).map((t) => t.key);
    busy.value = true;
    router.put(route('connectors.tools', c.key), { tools: on }, { preserveScroll: true, onFinish: () => (busy.value = false) });
};

const post = (c, action, question = null) => {
    if (question && !confirm(question)) return;
    busy.value = true;
    router.post(route(`connectors.${action}`, c.key), {}, { preserveScroll: true, onFinish: () => (busy.value = false) });
};

// the new key, shown once
const shownKey = ref(props.newKey);
watch(() => props.newKey, (k) => { if (k) shownKey.value = k; });
const copied = ref(false);
const copy = async (text) => { try { await navigator.clipboard.writeText(text); copied.value = true; setTimeout(() => (copied.value = false), 2000); } catch { /* the field stays selectable */ } };
</script>

<template>
    <AppLayout title="الموصلات">
        <PageHeader title="الموصلات" subtitle="اربط أنظمة الجامعة الأخرى بنظام الطلبة أو ألغِ ربطها. لكل موصل أدوات تختار تشغيلها، وإلغاء الربط يوقف وصوله فوراً." />

        <div class="mb-6 flex items-start gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
            <ShieldCheckIcon class="mt-0.5 h-5 w-5 shrink-0 text-brand-600" />
            <p>المفاتيح تُحفظ مشفرة ولا تظهر بعد إصدارها. كل اتصال يُسجَّل، وأي تغيير هنا يظهر في سجل التدقيق. درجات نظام الامتحانات لا تدخل السجل إلا بعد اعتماد تدريسي المادة.</p>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section v-for="c in connectors" :key="c.key" class="card flex flex-col">
                <div class="flex items-start gap-4 p-5">
                    <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl" :class="c.connected ? 'bg-brand-50 text-brand-700' : 'bg-slate-100 text-slate-400'">
                        <component :is="icons[c.key]" class="h-6 w-6" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-extrabold text-slate-900">{{ c.name }}</h2>
                            <span class="text-xs font-semibold text-slate-400" dir="ltr">{{ c.product }}</span>
                            <Badge :tone="c.connected ? 'emerald' : 'slate'" dot>{{ c.connected ? 'متصل' : 'غير متصل' }}</Badge>
                        </div>
                        <p class="mt-1 text-sm leading-6 text-slate-500">{{ c.description }}</p>
                    </div>
                </div>

                <!-- tools -->
                <div class="border-t border-slate-100 px-5 py-4">
                    <p class="mb-2 text-xs font-bold text-slate-500">الأدوات</p>
                    <ul class="space-y-2">
                        <li v-for="t in c.tools" :key="t.key" class="flex items-start gap-3 rounded-xl border border-slate-100 p-3" :class="!c.connected && 'opacity-60'">
                            <div class="min-w-0 flex-1">
                                <p class="flex items-center gap-2 text-sm font-bold text-slate-800">{{ t.name }}
                                    <Badge :tone="t.access === 'write' ? 'amber' : 'sky'">{{ t.access === 'write' ? 'كتابة' : 'قراءة' }}</Badge>
                                </p>
                                <p class="mt-0.5 text-xs leading-5 text-slate-500">{{ t.description }}</p>
                            </div>
                            <button type="button" role="switch" :aria-checked="t.on" :disabled="!c.connected || busy" :title="t.on ? 'إيقاف الأداة' : 'تشغيل الأداة'"
                                class="relative mt-0.5 inline-flex h-6 w-11 shrink-0 rounded-full transition disabled:cursor-not-allowed" :class="t.on ? 'bg-brand-600' : 'bg-slate-200'" @click="toggle(c, t)">
                                <span class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all" :class="t.on ? 'start-[1.375rem]' : 'start-0.5'" />
                            </button>
                        </li>
                    </ul>
                </div>

                <!-- state -->
                <div v-if="c.connected" class="space-y-3 border-t border-slate-100 px-5 py-4 text-sm">
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                        <template v-if="c.base_url"><dt class="text-slate-500">العنوان</dt><dd class="truncate font-mono text-slate-700" dir="ltr">{{ c.base_url }}</dd></template>
                        <template v-if="c.key_hint"><dt class="text-slate-500">المفتاح</dt><dd class="font-mono text-slate-700" dir="ltr">sisc_…{{ c.key_hint }}</dd></template>
                        <dt class="text-slate-500">مربوط منذ</dt><dd class="ltr text-slate-700">{{ c.connected_at }}</dd>
                        <dt class="text-slate-500">آخر اتصال ناجح</dt><dd class="ltr text-slate-700">{{ c.last_seen_at ?? 'لم يتصل بعد' }}</dd>
                        <template v-if="c.key === 'library'">
                            <dt class="text-slate-500">آخر مزامنة</dt><dd class="ltr text-slate-700">{{ c.last_sync_at ?? '—' }}</dd>
                            <dt class="text-slate-500">طلبة لديهم بيانات مكتبة</dt><dd class="text-slate-700">{{ num(c.stats.students) }} <span v-if="c.stats.not_clear" class="text-rose-700">({{ num(c.stats.not_clear) }} عليهم ذمة)</span></dd>
                        </template>
                        <template v-else>
                            <dt class="text-slate-500">دفعات درجات تنتظر التدريسيين</dt><dd class="text-slate-700">{{ num(c.stats.pending) }}</dd>
                        </template>
                    </dl>
                    <div v-if="c.last_error" class="flex items-start gap-2 rounded-xl bg-rose-50 px-3 py-2 text-xs text-rose-800">
                        <ExclamationTriangleIcon class="mt-0.5 h-4 w-4 shrink-0" /><p>{{ c.last_error }} <span class="ltr text-rose-500">{{ c.last_error_at }}</span></p>
                    </div>
                    <div v-if="c.key === 'exams'" class="rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        عنوان واجهة الربط لنظام الامتحانات: <span class="select-all font-mono" dir="ltr">{{ apiBase }}/exams</span>
                    </div>
                    <ul v-if="c.activity.length" class="divide-y divide-slate-100 rounded-xl border border-slate-100 text-xs">
                        <li v-for="(a, i) in c.activity" :key="i" class="flex items-center gap-2 px-3 py-1.5">
                            <CheckCircleIcon v-if="a.status === 'done'" class="h-4 w-4 text-emerald-600" /><ExclamationTriangleIcon v-else class="h-4 w-4 text-rose-600" />
                            <span class="font-semibold text-slate-700">{{ operations[a.operation] ?? a.operation }}</span>
                            <span v-if="a.error" class="truncate text-rose-700">{{ a.error }}</span>
                            <span class="ltr ms-auto shrink-0 text-slate-400">{{ a.at }}</span>
                        </li>
                    </ul>
                </div>

                <div class="mt-auto flex flex-wrap items-center gap-2 border-t border-slate-100 bg-slate-50/60 px-5 py-3">
                    <template v-if="c.connected">
                        <button v-if="c.key === 'library'" class="btn-secondary btn-sm" :disabled="busy" @click="post(c, 'test')"><BoltIcon class="h-4 w-4" /> اختبار الاتصال</button>
                        <button v-if="c.key === 'library'" class="btn-secondary btn-sm" :disabled="busy" @click="post(c, 'sync')"><ArrowPathIcon class="h-4 w-4" /> مزامنة الآن</button>
                        <button v-if="c.direction === 'in'" class="btn-secondary btn-sm" :disabled="busy" @click="post(c, 'rotate', 'إصدار مفتاح جديد يوقف المفتاح الحالي فوراً. متابعة؟')"><KeyIcon class="h-4 w-4" /> مفتاح جديد</button>
                        <button class="btn-ghost btn-sm ms-auto text-rose-700 hover:bg-rose-50" :disabled="busy" @click="post(c, 'disconnect', `إلغاء ربط «${c.name}»؟ سيتوقف وصوله إلى النظام فوراً.`)"><LinkSlashIcon class="h-4 w-4" /> إلغاء الربط</button>
                    </template>
                    <button v-else class="btn-primary btn-sm" @click="openConnect(c)"><LinkIcon class="h-4 w-4" /> ربط</button>
                </div>
            </section>
        </div>

        <!-- connect -->
        <Modal :show="!!connecting" max-width="lg" @close="connecting = null">
            <form v-if="connecting" class="p-6" @submit.prevent="connect">
                <h3 class="text-lg font-extrabold">ربط {{ connecting.name }}</h3>
                <template v-if="connecting.direction === 'out'">
                    <ol class="mt-3 list-decimal space-y-1 ps-5 text-sm text-slate-600">
                        <li>في FileNest افتح: إعدادات المكتبة ← «ربط نظام الطلبة» ← إصدار مفتاح.</li>
                        <li>الصق عنوان FileNest والمفتاح هنا. سيُختبر الاتصال قبل الحفظ.</li>
                    </ol>
                    <div class="mt-5 space-y-4">
                        <div><label class="label">عنوان FileNest</label><input v-model="form.base_url" class="input" dir="ltr" placeholder="https://filenest.lan" required /><InputError :message="form.errors.base_url" class="mt-1" /></div>
                        <div><label class="label">المفتاح</label><input v-model="form.token" type="password" class="input font-mono" dir="ltr" autocomplete="off" required /><InputError :message="form.errors.token" class="mt-1" /></div>
                    </div>
                </template>
                <template v-else>
                    <p class="mt-3 text-sm leading-6 text-slate-600">سيصدر النظام مفتاحاً خاصاً بنظام الامتحانات يظهر مرة واحدة. ضعه في إعدادات الربط في نظام الامتحانات مع عنوان الواجهة. يستطيع نظام الامتحانات بعدها قراءة المواد وقوائم الطلبة وإرسال الدرجات حسب الأدوات المفعّلة.</p>
                    <div class="mt-5"><label class="label">عنوان نظام الامتحانات (اختياري، للتوثيق)</label><input v-model="form.base_url" class="input" dir="ltr" placeholder="https://exams.lan" /><InputError :message="form.errors.base_url" class="mt-1" /></div>
                </template>
                <div class="mt-6 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="connecting = null">إلغاء</button><button class="btn-primary" :disabled="form.processing"><LinkIcon class="h-4 w-4" /> {{ form.processing ? 'جارٍ الاختبار…' : 'ربط' }}</button></div>
            </form>
        </Modal>

        <!-- the key, once -->
        <Modal :show="!!shownKey" max-width="lg" :closeable="false">
            <div v-if="shownKey" class="p-6">
                <h3 class="flex items-center gap-2 text-lg font-extrabold"><KeyIcon class="h-5 w-5 text-brand-600" /> مفتاح نظام الامتحانات</h3>
                <p class="mt-2 text-sm text-slate-600">انسخه الآن وضعه في نظام الامتحانات. لن يظهر مرة أخرى؛ إن ضاع أصدر مفتاحاً جديداً.</p>
                <div class="mt-4 flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <code class="min-w-0 flex-1 select-all break-all text-sm" dir="ltr">{{ shownKey.key }}</code>
                    <button class="btn-secondary btn-sm shrink-0" @click="copy(shownKey.key)"><ClipboardDocumentIcon class="h-4 w-4" /> {{ copied ? 'نُسخ' : 'نسخ' }}</button>
                </div>
                <p class="mt-3 text-xs text-slate-500">عنوان الواجهة: <span class="select-all font-mono" dir="ltr">{{ apiBase }}/exams</span></p>
                <div class="mt-6 flex justify-end"><button class="btn-primary" @click="shownKey = null">نسخته</button></div>
            </div>
        </Modal>
    </AppLayout>
</template>
