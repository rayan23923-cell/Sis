import 'package:material_ui/material_ui.dart';
import 'package:provider/provider.dart';

import 'screens/login_screen.dart';
import 'screens/staff_home.dart';
import 'screens/student_home.dart';
import 'session.dart';
import 'theme.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(ChangeNotifierProvider(create: (_) => Session()..start(), child: const SisApp()));
}

class SisApp extends StatelessWidget {
  const SisApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'نظام معلومات الطلبة',
      debugShowCheckedModeBanner: false,
      theme: buildTheme(Brightness.light),
      darkTheme: buildTheme(Brightness.dark),
      locale: const Locale('ar'),
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: const AuthGate(),
    );
  }
}

/// Splash while the saved sign-in is checked, then the sign-in page or the right home.
class AuthGate extends StatelessWidget {
  const AuthGate({super.key});

  @override
  Widget build(BuildContext context) {
    final s = context.watch<Session>();
    if (!s.ready) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    if (!s.signedIn) return const LoginScreen();
    return s.isStudent ? const StudentHome() : const StaffHome();
  }
}
