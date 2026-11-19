import 'package:material_ui/material_ui.dart';

import '../api.dart';
import '../theme.dart';

/// Choose between the hosted site and the university's own server on its network (sis.lan).
class ServerScreen extends StatefulWidget {
  const ServerScreen({super.key});

  @override
  State<ServerScreen> createState() => _ServerScreenState();
}

class _ServerScreenState extends State<ServerScreen> {
  late final address = TextEditingController(text: Api.instance.server == hostedUrl ? 'https://sis.lan' : Api.instance.server);
  bool busy = false;
  String? error;

  Future<void> use(String url) async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final info = await Api.instance.useServer(url);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('تم الاتصال: ${info['university'] ?? ''}')));
      Navigator.of(context).pop();
    } on ApiError catch (e) {
      setState(() => error = e.message);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('الخادم')),
      body: ListView(padding: const EdgeInsets.all(20), children: [
        Card(
          child: ListTile(
            leading: const Icon(Icons.public, color: Brand.c600),
            title: const Text('عبر الإنترنت', style: TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text(Uri.parse(hostedUrl).host, textDirection: TextDirection.ltr),
            trailing: Api.instance.server == hostedUrl ? const Icon(Icons.check_circle, color: Brand.green) : null,
            onTap: busy ? null : () => use(hostedUrl),
          ),
        ),
        const SizedBox(height: 20),
        const Text('خادم الجامعة (بدون إنترنت)', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
        const SizedBox(height: 6),
        const Text('اتصل بشبكة الجامعة، ثم اكتب عنوان الخادم كما يظهر في لوحة تحكم الخادم، مثل sis.lan أو رقم IP.', style: TextStyle(color: Brand.slate500)),
        const SizedBox(height: 12),
        TextField(controller: address, textDirection: TextDirection.ltr, keyboardType: TextInputType.url, decoration: const InputDecoration(labelText: 'عنوان الخادم', prefixIcon: Icon(Icons.lan_outlined))),
        if (error != null) Padding(padding: const EdgeInsets.only(top: 10), child: Text(error!, style: const TextStyle(color: Brand.red))),
        const SizedBox(height: 14),
        FilledButton(onPressed: busy ? null : () => use(address.text), child: busy ? const CircularProgressIndicator(color: Colors.white) : const Text('اتصال')),
        const SizedBox(height: 12),
        const Text('لاستخدام https على الشبكة المحلية، ثبّت شهادة الخادم أولاً من صفحة /setup على الخادم.', style: TextStyle(color: Brand.slate500, fontSize: 12)),
      ]),
    );
  }
}
