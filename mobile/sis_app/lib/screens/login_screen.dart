import 'dart:io' show Platform;

import 'package:material_ui/material_ui.dart';
import 'package:provider/provider.dart';

import '../api.dart';
import '../session.dart';
import '../theme.dart';
import 'server_screen.dart';

String deviceName() => '${Platform.isAndroid ? 'Android' : Platform.isIOS ? 'iPhone' : Platform.isWindows ? 'Windows' : 'App'} · تطبيق';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final login = TextEditingController();
  final password = TextEditingController();
  final code = TextEditingController();
  String? challenge;
  String? error;
  bool busy = false;
  bool hide = true;

  Future<void> submit() async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final r = challenge == null
          ? await Api.instance.post('/auth/login', {'email': login.text.trim(), 'password': password.text, 'device_name': deviceName()})
          : await Api.instance.post('/auth/two-factor', {'challenge': challenge, 'code': code.text.trim(), 'device_name': deviceName()});
      if (r['two_factor'] == true) {
        setState(() => challenge = r['challenge'] as String);
      } else if (mounted) {
        await context.read<Session>().signedInAs(r['token'] as String, Map<String, dynamic>.from(r['user'] as Map));
      }
    } on ApiError catch (e) {
      setState(() => error = e.message);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final server = Api.instance.server;
    final local = server != hostedUrl;
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: AutofillGroup(
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Center(
                    child: Container(
                      width: 72,
                      height: 72,
                      decoration: const BoxDecoration(color: Brand.c500, shape: BoxShape.circle),
                      child: const Icon(Icons.school_rounded, color: Colors.white, size: 40),
                    ),
                  ),
                  const SizedBox(height: 18),
                  Text('نظام معلومات الطلبة', textAlign: TextAlign.center, style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
                  const SizedBox(height: 6),
                  Text(challenge == null ? 'سجّل الدخول بالرقم الجامعي أو البريد الإلكتروني' : 'أدخل الرمز من تطبيق المصادقة، أو أحد رموز الاسترداد', textAlign: TextAlign.center, style: const TextStyle(color: Brand.slate500)),
                  const SizedBox(height: 28),
                  if (challenge == null) ...[
                    TextField(controller: login, keyboardType: TextInputType.emailAddress, textDirection: TextDirection.ltr, autofillHints: const [AutofillHints.username], decoration: const InputDecoration(labelText: 'الرقم الجامعي أو البريد', prefixIcon: Icon(Icons.badge_outlined))),
                    const SizedBox(height: 12),
                    TextField(
                      controller: password,
                      obscureText: hide,
                      textDirection: TextDirection.ltr,
                      autofillHints: const [AutofillHints.password],
                      onSubmitted: (_) => submit(),
                      decoration: InputDecoration(labelText: 'كلمة المرور', prefixIcon: const Icon(Icons.lock_outline), suffixIcon: IconButton(onPressed: () => setState(() => hide = !hide), icon: Icon(hide ? Icons.visibility_outlined : Icons.visibility_off_outlined))),
                    ),
                  ] else
                    TextField(controller: code, autofocus: true, keyboardType: TextInputType.visiblePassword, textAlign: TextAlign.center, textDirection: TextDirection.ltr, style: const TextStyle(fontSize: 22, letterSpacing: 4), onSubmitted: (_) => submit(), decoration: const InputDecoration(labelText: 'رمز التحقق')),
                  if (error != null) ...[
                    const SizedBox(height: 12),
                    Container(padding: const EdgeInsets.all(12), decoration: BoxDecoration(color: Brand.red.withValues(alpha: .08), borderRadius: BorderRadius.circular(12)), child: Text(error!, style: const TextStyle(color: Brand.red, fontWeight: FontWeight.w600))),
                  ],
                  const SizedBox(height: 20),
                  FilledButton(onPressed: busy ? null : submit, child: busy ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white)) : Text(challenge == null ? 'دخول' : 'تحقق')),
                  if (challenge != null) TextButton(onPressed: () => setState(() => challenge = null), child: const Text('رجوع')),
                  const SizedBox(height: 24),
                  OutlinedButton.icon(
                    onPressed: () async {
                      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const ServerScreen()));
                      setState(() {});
                    },
                    icon: Icon(local ? Icons.lan_outlined : Icons.public),
                    label: Text(local ? 'خادم الجامعة: ${Uri.parse(server).host}' : 'عبر الإنترنت · تغيير الخادم'),
                  ),
                  const SizedBox(height: 8),
                  const Text('الإصدار $appVersion', textAlign: TextAlign.center, style: TextStyle(color: Brand.slate500, fontSize: 12)),
                ]),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
