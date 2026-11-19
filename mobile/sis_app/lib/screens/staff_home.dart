import 'dart:io' show Platform;

import 'package:material_ui/material_ui.dart';
import 'package:provider/provider.dart';

import '../api.dart';
import '../session.dart';
import '../theme.dart';
import 'more_screen.dart';
import 'web.dart';

/// Staff (teachers, registrars, deans...) use the whole system: on phones inside the app, on
/// Windows in the browser, opened already signed in.
class StaffHome extends StatefulWidget {
  const StaffHome({super.key});

  @override
  State<StaffHome> createState() => _StaffHomeState();
}

class _StaffHomeState extends State<StaffHome> {
  final site = GlobalKey<SitePageState>();
  String? url;
  String? error;
  int tab = 0;

  bool get embedded => Platform.isAndroid || Platform.isIOS;

  @override
  void initState() {
    super.initState();
    if (embedded) link();
  }

  Future<void> link() async {
    try {
      final u = await Api.instance.webLink('/dashboard');
      if (mounted) setState(() => url = u);
    } on ApiError catch (e) {
      if (mounted) setState(() => error = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = context.watch<Session>();
    final user = session.user ?? {};
    Widget body;
    if (tab == 1) {
      body = const MoreScreen();
    } else if (!embedded) {
      body = Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 460),
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              const Icon(Icons.space_dashboard_outlined, size: 56, color: Brand.c600),
              const SizedBox(height: 12),
              Text('أهلاً ${user['name'] ?? ''}', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
              const SizedBox(height: 8),
              const Text('يفتح النظام في المتصفح وأنت مسجّل الدخول، مع كل الصفحات: المواد، الدرجات، الطلبة، التقارير.', textAlign: TextAlign.center, style: TextStyle(color: Brand.slate500)),
              const SizedBox(height: 20),
              FilledButton.icon(onPressed: () => openSite(context, '/dashboard'), icon: const Icon(Icons.open_in_new), label: const Text('فتح النظام')),
            ]),
          ),
        ),
      );
    } else if (url == null) {
      body = Center(child: error == null ? const CircularProgressIndicator() : Column(mainAxisSize: MainAxisSize.min, children: [Text(error!), const SizedBox(height: 12), FilledButton(onPressed: link, child: const Text('إعادة المحاولة'))]));
    } else {
      body = SitePage(key: site, url: url!, title: '', embedded: true);
    }
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) return;
        if (tab == 0 && await site.currentState?.back() == true) return;
        if (tab == 1) setState(() => tab = 0);
      },
      child: Scaffold(
        appBar: AppBar(
          title: Text(tab == 0 ? 'نظام معلومات الطلبة' : 'المزيد'),
          actions: [
            if (tab == 0 && embedded) IconButton(onPressed: () => site.currentState?.controller.reload(), icon: const Icon(Icons.refresh)),
            IconButton(
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
            NavigationDestination(icon: Icon(Icons.space_dashboard_outlined), selectedIcon: Icon(Icons.space_dashboard), label: 'النظام'),
            NavigationDestination(icon: Icon(Icons.menu_rounded), label: 'المزيد'),
          ],
        ),
      ),
    );
  }
}
