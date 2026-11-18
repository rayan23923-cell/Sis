<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import { systems, policyStatus } from '@/lib/labels';

defineProps({ curriculum: Object, stages: Array });
</script>

<template>
    <AppLayout :title="curriculum.name">
        <PageHeader :title="curriculum.name" :subtitle="`${curriculum.program} · ${curriculum.department} · ${curriculum.college}`"
            :crumbs="[{ label: 'الهيكل والمناهج', href: route('structure.index') }, { label: `إصدار ${curriculum.version}` }]">
            <Badge :map="policyStatus" :value="curriculum.status" dot />
            <Badge tone="brand">{{ systems[curriculum.system] }}</Badge>
        </PageHeader>
        <p class="mb-6 rounded-2xl bg-slate-100 px-4 py-3 text-sm text-slate-600">المنهج المعتمد لا يُعدَّل؛ أي تغيير يُنشئ إصداراً جديداً، ويبقى الطلبة على الإصدار الذي قُبلوا عليه.</p>
        <section v-for="s in stages" :key="s.stage" class="card mb-6 overflow-hidden">
            <div class="card-header"><h2 class="card-title">المرحلة {{ s.stage }}</h2><span class="text-sm text-slate-500">{{ s.credits }} وحدة<span v-if="s.ects"> · {{ s.ects }} ECTS</span></span></div>
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>الرمز</th><th>المادة</th><th>الفصل</th><th>الوحدات</th><th v-if="curriculum.system === 'bologna'">ECTS</th><th>النوع</th><th>المتطلب السابق</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="c in s.courses" :key="c.id">
                            <td class="font-mono text-xs font-bold text-brand-700">{{ c.code }}</td>
                            <td><p class="font-bold">{{ c.name }}</p><p class="text-xs text-slate-400" dir="ltr">{{ c.name_en }}</p></td>
                            <td>{{ c.term ? (c.term === 1 ? 'الأول' : 'الثاني') : 'سنوي' }}</td>
                            <td class="tabular-nums">{{ c.credits }}</td>
                            <td v-if="curriculum.system === 'bologna'" class="tabular-nums">{{ c.ects ?? '—' }}</td>
                            <td><Badge :tone="c.mandatory ? 'brand' : 'slate'">{{ c.mandatory ? 'إلزامية' : 'اختيارية' }}</Badge></td>
                            <td class="font-mono text-xs">{{ c.prerequisites.join('، ') || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
