<script setup>
import { watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({ show: Boolean, types: Object, type: { type: String, default: '' }, colleges: { type: Array, default: () => [] } });
const emit = defineEmits(['close', 'saved']);
const page = usePage();
const today = new Date().toISOString().slice(0, 10);
const form = useForm({ number: '', issued_on: today, type: props.type, title: '', body: '', issued_by_authority: 'رئاسة الجامعة', college_id: '' });
watch(() => props.show, (s) => { if (s) { form.reset(); form.clearErrors(); form.type = props.type || form.type; } });
const save = () => form.post(route('orders.store'), {
    preserveScroll: true,
    onSuccess: () => { emit('saved', page.props.flash?.created_order_id); emit('close'); },
});
</script>

<template>
    <Modal :show="show" max-width="lg" @close="emit('close')">
        <form class="p-6" @submit.prevent="save">
            <h3 class="text-lg font-extrabold text-slate-900">تسجيل أمر إداري</h3>
            <p class="mt-1 text-sm text-slate-500">سجّل الأمر كما ورد في الكتاب الرسمي. لا يُعدَّل الأمر بعد تسجيله.</p>
            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div><label class="label">رقم الأمر</label><input v-model="form.number" class="input" required autofocus /><InputError :message="form.errors.number" class="mt-1" /></div>
                <div><label class="label">تاريخه</label><input v-model="form.issued_on" type="date" :max="today" class="input" required /><InputError :message="form.errors.issued_on" class="mt-1" /></div>
                <div><label class="label">النوع</label><select v-model="form.type" class="input" required :disabled="!!type"><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select></div>
                <div><label class="label">الجهة المصدرة</label><input v-model="form.issued_by_authority" class="input" required /><InputError :message="form.errors.issued_by_authority" class="mt-1" /></div>
                <div class="sm:col-span-2"><label class="label">العنوان</label><input v-model="form.title" class="input" required placeholder="مثال: تأجيل دراسة الطالب للعام 2025-2026" /><InputError :message="form.errors.title" class="mt-1" /></div>
                <div v-if="colleges.length" class="sm:col-span-2"><label class="label">الكلية</label><select v-model="form.college_id" class="input"><option value="">على مستوى الجامعة</option><option v-for="c in colleges" :key="c.id" :value="c.id">{{ c.name_ar }}</option></select></div>
                <div class="sm:col-span-2"><label class="label">النص أو الملاحظات</label><textarea v-model="form.body" rows="3" class="input" /></div>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="emit('close')">إلغاء</button>
                <button class="btn-primary" :disabled="form.processing">تسجيل الأمر</button>
            </div>
        </form>
    </Modal>
</template>
