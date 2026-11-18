<script setup>
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { KeyIcon, PrinterIcon } from '@heroicons/vue/24/outline';

// Shows temporary portal passwords once, right after they are issued. They are not kept anywhere.
const page = usePage();
const items = ref([]);
watch(() => page.props.flash?.credentials, (c) => { if (c?.length) items.value = c; }, { immediate: true });
const close = () => (items.value = []);
const print = () => window.print();
</script>

<template>
    <Modal :show="items.length > 0" max-width="md" @close="close">
        <div class="p-6">
            <div class="flex items-center gap-3">
                <span class="grid h-10 w-10 place-items-center rounded-xl bg-brand-50 text-brand-700"><KeyIcon class="h-5 w-5" /></span>
                <div>
                    <h3 class="text-lg font-extrabold text-slate-900">حساب بوابة الطالب</h3>
                    <p class="text-sm text-slate-500">تظهر كلمة المرور الآن فقط. سلّمها للطالب؛ سيُطلب منه تغييرها عند أول دخول.</p>
                </div>
            </div>
            <div v-for="c in items" :key="c.number" class="mt-5 rounded-xl border border-dashed border-slate-300 p-4 text-sm">
                <p class="font-bold text-slate-800">{{ c.name }}</p>
                <dl class="mt-2 grid grid-cols-2 gap-y-1">
                    <dt class="text-slate-500">اسم الدخول</dt><dd class="ltr font-mono font-bold">{{ c.number }}</dd>
                    <dt class="text-slate-500">كلمة المرور المؤقتة</dt><dd class="ltr select-all font-mono text-lg font-extrabold tracking-wider text-brand-800">{{ c.password }}</dd>
                </dl>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button class="btn-secondary" @click="print"><PrinterIcon class="h-4 w-4" /> طباعة</button>
                <button class="btn-primary" @click="close">تم التسليم</button>
            </div>
        </div>
    </Modal>
</template>
