<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import { BuildingOfficeIcon, CalendarDaysIcon, PencilSquareIcon, PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ colleges: Array, rooms: Array, types: Object });
const show = ref(false);
const editing = ref(null);
const form = useForm({ college_id: props.colleges[0]?.id ?? '', code: '', name_ar: '', capacity: 40, type: 'lecture', is_active: true });
const openAdd = () => { editing.value = null; form.reset(); form.clearErrors(); show.value = true; };
const openEdit = (r) => { editing.value = r; form.clearErrors(); Object.assign(form, { college_id: r.college_id, code: r.code, name_ar: r.name_ar, capacity: r.capacity, type: r.type, is_active: r.is_active }); show.value = true; };
const save = () => {
    const opts = { preserveScroll: true, onSuccess: () => (show.value = false) };
    editing.value ? form.put(route('rooms.update', editing.value.id), opts) : form.post(route('rooms.store'), opts);
};
</script>

<template>
    <AppLayout title="القاعات">
        <PageHeader title="القاعات والمختبرات" subtitle="قاعات كل كلية وسعتها، وساعات إشغالها في الفصل الحالي.">
            <Link :href="route('timetable.index')" class="btn-secondary"><CalendarDaysIcon class="h-4 w-4" /> الجدول الأسبوعي</Link>
            <button class="btn-primary" @click="openAdd"><PlusIcon class="h-4 w-4" /> قاعة</button>
        </PageHeader>
        <section class="card">
            <EmptyState v-if="!rooms.length" :icon="BuildingOfficeIcon" title="لا توجد قاعات" text="أضف قاعات الكلية لتوزيع المحاضرات عليها." />
            <div v-else class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>الرمز</th><th>القاعة</th><th>الكلية</th><th>النوع</th><th>السعة</th><th>الإشغال الأسبوعي</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="r in rooms" :key="r.id" :class="!r.is_active && 'opacity-50'">
                            <td class="font-mono text-sm">{{ r.code }}</td>
                            <td class="font-bold text-slate-800">{{ r.name_ar }} <Badge v-if="!r.is_active" tone="slate">معطلة</Badge></td>
                            <td class="text-sm">{{ r.college }}</td>
                            <td class="text-sm">{{ types[r.type] }}</td>
                            <td class="tabular-nums">{{ r.capacity }}</td>
                            <td><div class="flex items-center gap-2"><div class="h-1.5 w-24 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-brand-500" :style="{ width: Math.min(100, (r.hours / 40) * 100) + '%' }"></div></div><span class="text-xs tabular-nums text-slate-500">{{ r.hours }} س</span></div></td>
                            <td><button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-brand-700" title="تعديل" @click="openEdit(r)"><PencilSquareIcon class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
        <Modal :show="show" max-width="lg" @close="show = false">
            <form class="p-6" @submit.prevent="save">
                <h3 class="text-lg font-extrabold">{{ editing ? 'تعديل قاعة' : 'إضافة قاعة' }}</h3>
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2"><label class="label">الكلية</label><select v-model="form.college_id" class="input"><option v-for="c in colleges" :key="c.id" :value="c.id">{{ c.name_ar }}</option></select></div>
                    <div><label class="label">الرمز</label><input v-model="form.code" class="input" dir="ltr" required /><InputError :message="form.errors.code" class="mt-1" /></div>
                    <div><label class="label">الاسم</label><input v-model="form.name_ar" class="input" required /><InputError :message="form.errors.name_ar" class="mt-1" /></div>
                    <div><label class="label">النوع</label><select v-model="form.type" class="input"><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select></div>
                    <div><label class="label">السعة</label><input v-model.number="form.capacity" type="number" min="0" class="input" /></div>
                    <label class="flex items-center gap-2 text-sm font-semibold"><input v-model="form.is_active" type="checkbox" class="rounded border-slate-300 text-brand-600" /> فعالة</label>
                </div>
                <div class="mt-6 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="show = false">إلغاء</button><button class="btn-primary" :disabled="form.processing">حفظ</button></div>
            </form>
        </Modal>
    </AppLayout>
</template>
