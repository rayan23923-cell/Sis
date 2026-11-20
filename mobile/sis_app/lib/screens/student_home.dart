import 'package:material_ui/material_ui.dart';
import 'package:provider/provider.dart';

import '../api.dart';
import '../session.dart';
import '../theme.dart';
import 'more_screen.dart';
import 'web.dart';

String money(num v) {
  final s = v.round().toString();
  final b = StringBuffer();
  for (var i = 0; i < s.length; i++) {
    if (i > 0 && (s.length - i) % 3 == 0) b.write(',');
    b.write(s[i]);
  }
  return '$b د.ع';
}

/// The student's portal: home, results, timetable, fees and more.
class StudentHome extends StatefulWidget {
  const StudentHome({super.key});

  @override
  State<StudentHome> createState() => _StudentHomeState();
}

class _StudentHomeState extends State<StudentHome> {
  int tab = 0;
  Map<String, dynamic>? data;
  String? error;

  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    try {
      final d = await Api.instance.get('/student');
      if (mounted) {
        setState(() {
          data = d;
          error = null;
        });
      }
      if (mounted) context.read<Session>().refresh();
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final mustChange = session.user?['must_change_password'] == true;
    const titles = ['الرئيسية', 'النتائج', 'جدولي', 'الرسوم', 'المزيد'];
    Widget body;
    if (mustChange) {
      body = _Notice(
        icon: Icons.key_outlined,
        title: 'غيّر كلمة المرور أولاً',
        text: 'هذا أول دخول لك. غيّر كلمة المرور المؤقتة ثم ارجع للتطبيق واسحب للتحديث.',
        action: FilledButton(onPressed: () async {
          await openSite(context, '/portal', title: 'تغيير كلمة المرور');
          await session.refresh();
          load();
        }, child: const Text('تغيير كلمة المرور')),
      );
    } else if (data == null) {
      body = error == null ? const Center(child: CircularProgressIndicator()) : _Notice(icon: Icons.cloud_off, title: 'تعذّر التحميل', text: error!, action: FilledButton(onPressed: load, child: const Text('إعادة المحاولة')));
    } else {
      body = RefreshIndicator(
        onRefresh: load,
        child: [
          () => _Overview(data: data!, onTab: (t) => setState(() => tab = t)),
          () => _Results(data: data!),
          () => _Timetable(data: data!),
          () => _Fees(data: data!),
          () => MoreScreen(onChanged: load),
        ][tab](),
      );
    }
    return Scaffold(
      appBar: AppBar(
        title: Text(titles[tab]),
        actions: [
          IconButton(
            tooltip: 'الإشعارات',
            onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const NotificationsScreen())),
            icon: Badge(isLabelVisible: session.unread > 0, label: Text('${session.unread}'), child: const Icon(Icons.notifications_none_rounded)),
          ),
        ],
      ),
      body: body,
      bottomNavigationBar: NavigationBar(
        selectedIndex: tab,
        onDestinationSelected: (i) => setState(() => tab = i),
        destinations: const [
          NavigationDestination(icon: Icon(Icons.home_outlined), selectedIcon: Icon(Icons.home_rounded), label: 'الرئيسية'),
          NavigationDestination(icon: Icon(Icons.bar_chart_outlined), selectedIcon: Icon(Icons.bar_chart_rounded), label: 'النتائج'),
          NavigationDestination(icon: Icon(Icons.calendar_month_outlined), selectedIcon: Icon(Icons.calendar_month_rounded), label: 'جدولي'),
          NavigationDestination(icon: Icon(Icons.payments_outlined), selectedIcon: Icon(Icons.payments_rounded), label: 'الرسوم'),
          NavigationDestination(icon: Icon(Icons.menu_rounded), label: 'المزيد'),
        ],
      ),
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({required this.icon, required this.title, required this.text, this.action});
  final IconData icon;
  final String title;
  final String text;
  final Widget? action;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Icon(icon, size: 48, color: Brand.c500),
            const SizedBox(height: 12),
            Text(title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
            const SizedBox(height: 6),
            Text(text, textAlign: TextAlign.center, style: const TextStyle(color: Brand.slate500)),
            if (action != null) ...[const SizedBox(height: 18), action!],
          ]),
        ),
      );
}

Widget section(String title, {Widget? trailing}) => Padding(
      padding: const EdgeInsets.fromLTRB(4, 18, 4, 8),
      child: Row(children: [Expanded(child: Text(title, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15))), ?trailing]),
    );

class _Overview extends StatelessWidget {
  const _Overview({required this.data, required this.onTab});
  final Map<String, dynamic> data;
  final ValueChanged<int> onTab;

  @override
  Widget build(BuildContext context) {
    final s = data['student'] as Map;
    final current = (data['current'] as List).cast<Map>();
    final evaluations = (data['evaluations'] as List?) ?? [];
    final fees = (data['fees'] as List).cast<Map>();
    final balance = fees.fold<num>(0, (a, f) => a + (f['balance'] as num));
    final announced = current.where((c) => c['result'] != null).length;
    return ListView(padding: const EdgeInsets.all(16), children: [
      Container(
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(color: Brand.c500, borderRadius: BorderRadius.circular(14)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('${s['name']}', style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w800)),
          const SizedBox(height: 4),
          Text('${s['number']}', textDirection: TextDirection.ltr, style: const TextStyle(color: Colors.white70, fontFamily: 'monospace')),
          const SizedBox(height: 12),
          Text('${s['college'] ?? ''} · ${s['department'] ?? ''}', style: const TextStyle(color: Colors.white)),
          Text('${s['program'] ?? ''}', style: const TextStyle(color: Colors.white70, fontSize: 13)),
          const SizedBox(height: 12),
          Wrap(spacing: 8, runSpacing: 8, children: [
            _chip('المرحلة ${s['stage'] ?? '—'}'),
            _chip('${s['status_label']}'),
            if (s['system'] != null) _chip('${s['system']}'),
          ]),
        ]),
      ),
      if (data['official'] != true)
        Padding(
          padding: const EdgeInsets.only(top: 10),
          child: Text('النتائج المعروضة غير رسمية إلى أن تعتمدها الجامعة.', style: TextStyle(color: Brand.amber.withValues(alpha: .9), fontSize: 12)),
        ),
      const SizedBox(height: 14),
      Row(children: [
        _stat(context, 'نتائج معلنة', '$announced / ${current.length}', Brand.green, () => onTab(1)),
        const SizedBox(width: 10),
        _stat(context, 'المتبقي من الرسوم', balance > 0 ? money(balance) : 'لا شيء', balance > 0 ? Brand.red : Brand.green, () => onTab(3)),
      ]),
      if (evaluations.isNotEmpty) ...[
        const SizedBox(height: 12),
        Card(
          color: Brand.c50,
          child: ListTile(
            leading: const Icon(Icons.star_rate_rounded, color: Brand.amber),
            title: Text('قيّم ${evaluations.length} من موادك', style: const TextStyle(fontWeight: FontWeight.w700, color: Brand.c900)),
            subtitle: const Text('التقييم سري ولا يظهر اسمك للتدريسي.', style: TextStyle(color: Brand.c700)),
            trailing: const Icon(Icons.chevron_left, color: Brand.c700),
            onTap: () => openSite(context, '/portal?tab=evaluate', title: 'تقييم المواد'),
          ),
        ),
      ],
      section('مواد هذا العام'),
      for (final c in current) _CourseTile(c: c),
      if (current.isEmpty) const Padding(padding: EdgeInsets.all(16), child: Text('لا توجد مواد مسجلة لهذا العام.', style: TextStyle(color: Brand.slate500))),
    ]);
  }

  Widget _chip(String t) => Container(padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4), decoration: BoxDecoration(color: Colors.white.withValues(alpha: .15), borderRadius: BorderRadius.circular(20)), child: Text(t, style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w600)));

  Widget _stat(BuildContext context, String label, String value, Color color, VoidCallback onTap) => Expanded(
        child: Card(
          child: InkWell(
            borderRadius: BorderRadius.circular(16),
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(label, style: const TextStyle(color: Brand.slate500, fontSize: 12)),
                const SizedBox(height: 4),
                FittedBox(child: Text(value, style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: color))),
              ]),
            ),
          ),
        ),
      );
}

class _CourseTile extends StatelessWidget {
  const _CourseTile({required this.c});
  final Map c;

  @override
  Widget build(BuildContext context) {
    final r = c['result'] as Map?;
    final absence = c['absence'] as Map?;
    final failed = r != null && r['outcome'] == 'fail';
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${c['name']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                const SizedBox(height: 2),
                Text('${c['code']} · ${c['term']}${c['teacher'] != null ? ' · ${c['teacher']}' : ''}', style: const TextStyle(color: Brand.slate500, fontSize: 12)),
                if (absence != null)
                  Padding(padding: const EdgeInsets.only(top: 4), child: Text('الغياب: ${absence['absent'] ?? 0} من ${absence['sessions']}', style: const TextStyle(color: Brand.amber, fontSize: 12, fontWeight: FontWeight.w600))),
              ]),
            ),
            if (r != null)
              Column(children: [
                Text('${(r['mark'] as num).round()}', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: failed ? Brand.red : Brand.green)),
                Text('${r['grade'] ?? ''}', style: TextStyle(fontSize: 12, color: failed ? Brand.red : Brand.slate500)),
              ])
            else
              const Text('لم تُعلن', style: TextStyle(color: Brand.slate500, fontSize: 12)),
          ]),
        ),
      ),
    );
  }
}

class _Results extends StatelessWidget {
  const _Results({required this.data});
  final Map<String, dynamic> data;

  @override
  Widget build(BuildContext context) {
    final years = (data['years'] as List).cast<Map>();
    final id = (data['student'] as Map)['id'];
    return ListView(padding: const EdgeInsets.all(16), children: [
      OutlinedButton.icon(onPressed: () => openSite(context, '/students/$id/transcript.pdf'), icon: const Icon(Icons.picture_as_pdf_outlined), label: const Text('كشف الدرجات PDF')),
      if (years.isEmpty) const Padding(padding: EdgeInsets.all(24), child: Text('لا توجد نتائج بعد.', textAlign: TextAlign.center, style: TextStyle(color: Brand.slate500))),
      for (final y in years) ...[
        section('العام ${y['name']}', trailing: Text(y['gpa'] != null ? 'المعدل ${y['gpa']}' : '', style: const TextStyle(fontWeight: FontWeight.w800, color: Brand.c600))),
        if (y['decision'] != null) Padding(padding: const EdgeInsets.only(bottom: 8, right: 4), child: Text('${y['decision']}', style: const TextStyle(color: Brand.slate500))),
        Card(
          child: Column(children: [
            for (final (i, r) in (y['rows'] as List).cast<Map>().indexed) ...[
              if (i > 0) const Divider(height: 1),
              ListTile(
                dense: true,
                title: Text('${r['name']}', style: const TextStyle(fontWeight: FontWeight.w600)),
                subtitle: Text('${r['code']} · ${r['term']} · ${r['credits']} وحدات${r['round'] != '—' ? ' · الدور ${r['round']}' : ''}'),
                trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                  Text(r['mark'] == null ? '—' : '${(r['mark'] as num).round()}', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16, color: r['failed'] == true ? Brand.red : null)),
                  Text('${r['grade'] ?? r['outcome']}', style: TextStyle(fontSize: 11, color: r['failed'] == true ? Brand.red : Brand.slate500)),
                ]),
              ),
            ],
          ]),
        ),
      ],
    ]);
  }
}

class _Timetable extends StatelessWidget {
  const _Timetable({required this.data});
  final Map<String, dynamic> data;

  @override
  Widget build(BuildContext context) {
    final slots = (data['timetable'] as List).cast<Map>();
    final days = (data['days'] as List).cast<String>();
    final today = (DateTime.now().weekday + 1) % 7; // Saturday = 0
    return ListView(padding: const EdgeInsets.all(16), children: [
      OutlinedButton.icon(onPressed: () => openSite(context, '/my-timetable.pdf'), icon: const Icon(Icons.picture_as_pdf_outlined), label: const Text('الجدول PDF للطباعة')),
      if (slots.isEmpty) const Padding(padding: EdgeInsets.all(24), child: Text('لم يُنشر جدول لهذا الفصل بعد.', textAlign: TextAlign.center, style: TextStyle(color: Brand.slate500))),
      for (final (d, name) in days.indexed)
        if (slots.any((s) => s['day'] == d)) ...[
          section(name, trailing: d == today ? const Text('اليوم', style: TextStyle(color: Brand.c600, fontWeight: FontWeight.w800)) : null),
          for (final s in slots.where((s) => s['day'] == d))
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Card(
                child: IntrinsicHeight(
                  child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    Container(width: 5, decoration: BoxDecoration(color: s['kind'] == 'lab' ? Brand.amber : Brand.c500, borderRadius: const BorderRadius.horizontal(right: Radius.circular(16)))),
                    Padding(
                      padding: const EdgeInsets.all(12),
                      child: Column(children: [
                        Text('${s['starts_at']}', style: const TextStyle(fontWeight: FontWeight.w800)),
                        Text('${s['ends_at']}', style: const TextStyle(color: Brand.slate500, fontSize: 12)),
                      ]),
                    ),
                    Expanded(
                      child: Padding(
                        padding: const EdgeInsets.symmetric(vertical: 12),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text('${s['name']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                          Text([s['code'], if (s['room'] != null) 'قاعة ${s['room']}', if (s['teacher'] != null) s['teacher']].join(' · '), style: const TextStyle(color: Brand.slate500, fontSize: 12)),
                        ]),
                      ),
                    ),
                  ]),
                ),
              ),
            ),
        ],
    ]);
  }
}

class _Fees extends StatelessWidget {
  const _Fees({required this.data});
  final Map<String, dynamic> data;

  @override
  Widget build(BuildContext context) {
    final fees = (data['fees'] as List).cast<Map>();
    final gateways = (data['gateways'] as Map?) ?? {};
    return ListView(padding: const EdgeInsets.all(16), children: [
      if (fees.isEmpty) const Padding(padding: EdgeInsets.all(24), child: Text('لا توجد رسوم مسجلة.', textAlign: TextAlign.center, style: TextStyle(color: Brand.slate500))),
      for (final f in fees) ...[
        section('رسوم ${f['year']}', trailing: Text(f['status'] == 'paid' ? 'مسددة' : 'المتبقي ${money(f['balance'] as num)}', style: TextStyle(fontWeight: FontWeight.w800, color: f['status'] == 'paid' ? Brand.green : Brand.red))),
        Card(
          child: Column(children: [
            for (final l in (f['lines'] as List).cast<Map>())
              ListTile(
                dense: true,
                leading: Icon(l['status'] == 'paid' ? Icons.check_circle : Icons.schedule, color: l['status'] == 'paid' ? Brand.green : Brand.amber),
                title: Text('القسط ${l['number']} · ${money(l['amount'] as num)}'),
                subtitle: Text('الاستحقاق ${l['due_on']}'),
              ),
            if ((f['payments'] as List).isNotEmpty) const Divider(height: 1),
            for (final p in (f['payments'] as List).cast<Map>())
              ListTile(
                dense: true,
                leading: const Icon(Icons.receipt_long_outlined),
                title: Text('وصل ${p['receipt']} · ${money(p['amount'] as num)}'),
                subtitle: Text('${p['paid_on']}'),
                trailing: const Icon(Icons.picture_as_pdf_outlined, size: 20),
                onTap: () => openSite(context, '/finance/receipts/${p['id']}.pdf'),
              ),
          ]),
        ),
        if (f['status'] == 'open' && gateways.isNotEmpty && (f['next'] as num) > 0)
          Padding(
            padding: const EdgeInsets.only(top: 10),
            child: FilledButton.icon(onPressed: () => openSite(context, '/portal?tab=fees', title: 'الدفع الإلكتروني'), icon: const Icon(Icons.credit_card), label: Text('ادفع ${money(f['next'] as num)} إلكترونياً')),
          ),
      ],
    ]);
  }
}
