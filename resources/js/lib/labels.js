// Arabic labels and colours shared by every screen.
export const offeringStatus = {
    open: { label: 'إدخال الدرجات', tone: 'sky' },
    submitted: { label: 'بانتظار اللجنة', tone: 'amber' },
    approved: { label: 'مصادق عليها', tone: 'violet' },
    locked: { label: 'مقفلة', tone: 'emerald' },
};

export const studentStatus = {
    active: { label: 'مستمر', tone: 'emerald' },
    postponed: { label: 'مؤجل', tone: 'amber' },
    hosted: { label: 'مستضاف', tone: 'sky' },
    transferred_out: { label: 'منقول', tone: 'slate' },
    dismissed: { label: 'مرقن قيده', tone: 'rose' },
    withdrawn: { label: 'منسحب', tone: 'slate' },
    graduated: { label: 'متخرج', tone: 'violet' },
};

export const outcome = {
    pass: { label: 'ناجح', tone: 'emerald' },
    fail: { label: 'راسب', tone: 'rose' },
    resit: { label: 'دور ثانٍ', tone: 'amber' },
    absent: { label: 'غائب', tone: 'slate' },
    withdrawn: { label: 'منسحب', tone: 'slate' },
};

export const resultStatus = {
    draft: { label: 'مسودة', tone: 'slate' },
    approved: { label: 'مصادق', tone: 'violet' },
    locked: { label: 'مقفلة', tone: 'emerald' },
};

export const policyStatus = {
    draft: { label: 'مسودة', tone: 'slate' },
    approved: { label: 'معتمدة', tone: 'emerald' },
    retired: { label: 'متقاعدة', tone: 'slate' },
};

export const systems = { bologna: 'بولونيا', semester: 'فصلي', annual: 'سنوي', any: 'جميع الأنظمة' };

export const roundName = (r) => (r === 1 ? 'الدور الأول' : r === 2 ? 'الدور الثاني' : `الدور ${r}`);

export const tones = {
    emerald: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    rose: 'bg-rose-50 text-rose-700 ring-rose-600/20',
    amber: 'bg-amber-50 text-amber-800 ring-amber-600/25',
    sky: 'bg-brand-50 text-brand-700 ring-brand-600/20',
    violet: 'bg-brand-100 text-brand-900 ring-brand-700/25',
    slate: 'bg-slate-100 text-slate-600 ring-slate-500/20',
    brand: 'bg-brand-50 text-brand-700 ring-brand-600/20',
};

export const num = (v, d = 0) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('en-US', { maximumFractionDigits: d }));
