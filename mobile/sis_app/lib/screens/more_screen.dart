import 'package:material_ui/material_ui.dart';
import 'package:provider/provider.dart';

import '../api.dart';
import '../session.dart';
import '../theme.dart';
import 'web.dart';

/// Everything else, opened in the site already signed in.
class MoreScreen extends StatelessWidget {
  const MoreScreen({super.key, this.onChanged});
  final VoidCallback? onChanged;

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final u = session.user ?? {};
    final student = session.isStudent;
    Widget item(IconData icon, String title, String path, {String? subtitle}) => ListTile(
          leading: Icon(icon, color: Brand.c600),
          title: Text(title, style: const TextStyle(fontWeight: FontWeight.w600)),
          subtitle: subtitle == null ? null : Text(subtitle),
          trailing: const Icon(Icons.chevron_left),
          onTap: () async {
            await openSite(context, path, title: title);
            onChanged?.call();
          },
        );
    return ListView(padding: const EdgeInsets.all(16), children: [
      Card(
        child: ListTile(
          leading: CircleAvatar(backgroundColor: Brand.c100, child: Text('${u['name'] ?? ' '}'.characters.first, style: const TextStyle(color: Brand.c700, fontWeight: FontWeight.w800))),
          title: Text('${u['name'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.w700)),
          subtitle: Text('${u['student_number'] ?? u['email'] ?? ''}', textDirection: TextDirection.ltr, textAlign: TextAlign.right),
        ),
      ),
      const SizedBox(height: 12),
      Card(
        child: Column(children: [
          if (student) ...[
            item(Icons.description_outlined, 'طلب وثيقة', '/portal?tab=requests', subtitle: 'كشف درجات، تأييد استمرار...'),
            item(Icons.history, 'سجلي الدراسي', '/portal?tab=history'),
            item(Icons.star_outline_rounded, 'تقييم المواد', '/portal?tab=evaluate'),
          ] else
            item(Icons.dashboard_outlined, 'لوحة النظام', '/dashboard'),
          item(Icons.key_outlined, 'كلمة المرور', student ? '/portal?tab=account' : '/profile'),
          item(Icons.shield_outlined, 'الأمان والتحقق بخطوتين', '/security'),
        ]),
      ),
      const SizedBox(height: 12),
      Card(
        child: Column(children: [
          ListTile(
            leading: const Icon(Icons.dns_outlined, color: Brand.slate500),
            title: const Text('الخادم'),
            subtitle: Text(Uri.parse(Api.instance.server).host, textDirection: TextDirection.ltr, textAlign: TextAlign.right),
          ),
          ListTile(
            leading: const Icon(Icons.logout, color: Brand.red),
            title: const Text('تسجيل الخروج', style: TextStyle(color: Brand.red, fontWeight: FontWeight.w700)),
            onTap: () async {
              final ok = await showDialog<bool>(
                context: context,
                builder: (c) => AlertDialog(
                  title: const Text('تسجيل الخروج؟'),
                  actions: [TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('إلغاء')), FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('خروج'))],
                ),
              );
              if (ok == true && context.mounted) await context.read<Session>().signOut();
            },
          ),
        ]),
      ),
      const SizedBox(height: 12),
      Text('${u['university'] ?? ''} · الإصدار $appVersion', textAlign: TextAlign.center, style: const TextStyle(color: Brand.slate500, fontSize: 12)),
    ]);
  }
}

class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  List<Map>? items;
  String? error;

  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    try {
      final r = await Api.instance.get('/notifications');
      if (!mounted) return;
      setState(() => items = (r['items'] as List).cast<Map>());
      context.read<Session>().setUnread((r['unread'] as num).toInt());
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  Future<void> open(Map n) async {
    final session = context.read<Session>();
    final r = await Api.instance.post('/notifications/read', {'id': n['id']});
    session.setUnread((r['unread'] as num).toInt());
    final url = n['url'] as String?;
    if (url != null && url.startsWith(Api.instance.server) && mounted) {
      await openSite(context, url.substring(Api.instance.server.length), title: '${n['title']}');
    }
    load();
  }

  @override
  Widget build(BuildContext context) {
    final colors = {'success': Brand.green, 'warning': Brand.amber, 'danger': Brand.red};
    return Scaffold(
      appBar: AppBar(title: const Text('الإشعارات'), actions: [
        TextButton(
          onPressed: () async {
            final session = context.read<Session>();
            final r = await Api.instance.post('/notifications/read');
            session.setUnread((r['unread'] as num).toInt());
            load();
          },
          child: const Text('تعليم الكل كمقروء'),
        ),
      ]),
      body: items == null
          ? Center(child: error == null ? const CircularProgressIndicator() : Text(error!))
          : RefreshIndicator(
              onRefresh: load,
              child: items!.isEmpty
                  ? ListView(children: const [Padding(padding: EdgeInsets.all(40), child: Text('لا توجد إشعارات.', textAlign: TextAlign.center, style: TextStyle(color: Brand.slate500)))])
                  : ListView.separated(
                      itemCount: items!.length,
                      separatorBuilder: (_, _) => const Divider(height: 1),
                      itemBuilder: (_, i) {
                        final n = items![i];
                        final read = n['read'] == true;
                        return ListTile(
                          tileColor: read ? null : Brand.c50.withValues(alpha: .6),
                          leading: Icon(Icons.circle, size: 12, color: read ? Colors.transparent : (colors[n['kind']] ?? Brand.c500)),
                          title: Text('${n['title']}', style: TextStyle(fontWeight: read ? FontWeight.w500 : FontWeight.w800)),
                          subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            if ('${n['body']}'.isNotEmpty) Text('${n['body']}'),
                            Text('${n['at']}', style: const TextStyle(fontSize: 11, color: Brand.slate500)),
                          ]),
                          onTap: () => open(n),
                        );
                      },
                    ),
            ),
    );
  }
}
