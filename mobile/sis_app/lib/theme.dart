import 'package:material_ui/material_ui.dart';

/// The site's colours (tailwind `brand`) so the apps and the site look like one system.
class Brand {
  static const c50 = Color(0xFFEEF6FB);
  static const c100 = Color(0xFFD5E8F4);
  static const c500 = Color(0xFF2A70A6);
  static const c600 = Color(0xFF1F598A);
  static const c700 = Color(0xFF1A4870);
  static const c900 = Color(0xFF0F2C45);
  static const slate50 = Color(0xFFF8FAFC);
  static const slate200 = Color(0xFFE2E8F0);
  static const slate500 = Color(0xFF64748B);
  static const slate800 = Color(0xFF1E293B);
  static const green = Color(0xFF059669);
  static const red = Color(0xFFE11D48);
  static const amber = Color(0xFFD97706);
}

ThemeData buildTheme(Brightness b) {
  final dark = b == Brightness.dark;
  final scheme = ColorScheme.fromSeed(seedColor: Brand.c600, brightness: b, primary: dark ? const Color(0xFF79B1D7) : Brand.c600);
  return ThemeData(
    colorScheme: scheme,
    brightness: b,
    fontFamilyFallback: const ['Segoe UI', 'Tahoma', 'Roboto'],
    scaffoldBackgroundColor: dark ? const Color(0xFF0B1220) : Brand.slate50,
    appBarTheme: AppBarTheme(
      backgroundColor: dark ? const Color(0xFF0F172A) : Colors.white,
      foregroundColor: dark ? Colors.white : Brand.slate800,
      elevation: 0,
      scrolledUnderElevation: 0.5,
      centerTitle: false,
      titleTextStyle: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: dark ? Colors.white : Brand.slate800),
    ),
    cardTheme: CardThemeData(
      elevation: 0,
      color: dark ? const Color(0xFF111827) : Colors.white,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: BorderSide(color: dark ? const Color(0xFF1F2937) : Brand.slate200)),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: dark ? const Color(0xFF111827) : Colors.white,
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Brand.slate200)),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide(color: dark ? const Color(0xFF334155) : Brand.slate200)),
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)), textStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
    ),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: dark ? const Color(0xFF0F172A) : Colors.white,
      indicatorColor: dark ? const Color(0xFF1A4870) : Brand.c100,
      labelTextStyle: WidgetStateProperty.all(const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
    ),
  );
}
