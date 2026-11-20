import 'package:material_ui/material_ui.dart';

/// The same Telegram-inspired tokens as the FileNest apps (FileNest
/// `lib/app/theme/app_colors.dart`) and the site's tailwind `brand`/`slate`:
/// one bright blue accent on clean neutral surfaces, Telegram's "night"
/// palette in dark mode. No gradients and no purple/indigo tones.
class Brand {
  // accent: Telegram's blue (FileNest AppColors.primary / primaryVariant)
  static const c50 = Color(0xFFEBF6FD);
  static const c100 = Color(0xFFD4ECFA);
  static const c500 = Color(0xFF2AABEE);
  static const c600 = Color(0xFF229ED9);
  static const c700 = Color(0xFF1A87BD);
  static const c900 = Color(0xFF155A7C);

  // neutrals: Telegram's light palette
  static const slate50 = Color(0xFFF4F4F5); // page background
  static const slate200 = Color(0xFFE4E4E6); // dividers
  static const slate500 = Color(0xFF707579); // muted text
  static const slate800 = Color(0xFF1B1E21); // text

  // status
  static const green = Color(0xFF34A853);
  static const red = Color(0xFFE53935);
  static const amber = Color(0xFFE59400);

  // dark: Telegram Desktop "night"
  static const darkBackground = Color(0xFF17212B);
  static const darkSurface = Color(0xFF232E3C);
  static const darkSurfaceVariant = Color(0xFF2B3A4A);
  static const darkDivider = Color(0xFF101921);
}

ThemeData buildTheme(Brightness b) {
  final dark = b == Brightness.dark;
  final surface = dark ? Brand.darkSurface : Colors.white;
  final background = dark ? Brand.darkBackground : Brand.slate50;
  final divider = dark ? Brand.darkDivider : Brand.slate200;
  final text = dark ? const Color(0xFFF5F5F5) : Brand.slate800;
  final muted = dark ? const Color(0xFF7D8B99) : Brand.slate500;
  final scheme = ColorScheme.fromSeed(seedColor: Brand.c500, brightness: b).copyWith(
    primary: Brand.c600,
    onPrimary: Colors.white,
    surface: surface,
    onSurface: text,
    onSurfaceVariant: muted,
    surfaceContainerHighest: dark ? Brand.darkSurfaceVariant : const Color(0xFFECECED),
    outlineVariant: divider,
    error: Brand.red,
  );
  return ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    brightness: b,
    fontFamilyFallback: const ['Segoe UI', 'Tahoma', 'Roboto'],
    scaffoldBackgroundColor: background,
    dividerColor: divider,
    appBarTheme: AppBarTheme(
      backgroundColor: surface,
      foregroundColor: text,
      elevation: 0,
      scrolledUnderElevation: 0.5,
      centerTitle: false,
      titleTextStyle: TextStyle(fontSize: 19, fontWeight: FontWeight.w600, color: text),
    ),
    cardTheme: CardThemeData(
      elevation: 0,
      color: surface,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
    ),
    listTileTheme: ListTileThemeData(
      iconColor: muted,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: dark ? Brand.darkSurfaceVariant : Colors.white,
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Brand.c500, width: 1.5)),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: Brand.c500,
        foregroundColor: Colors.white,
        minimumSize: const Size.fromHeight(50),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        textStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12))),
    ),
    floatingActionButtonTheme: const FloatingActionButtonThemeData(backgroundColor: Brand.c500, foregroundColor: Colors.white),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: surface,
      elevation: 0,
      indicatorColor: Brand.c500.withValues(alpha: .16),
      labelTextStyle: WidgetStateProperty.resolveWith((s) {
        final on = s.contains(WidgetState.selected);
        return TextStyle(fontSize: 12, fontWeight: on ? FontWeight.w600 : FontWeight.w400, color: on ? Brand.c500 : muted);
      }),
      iconTheme: WidgetStateProperty.resolveWith((s) => IconThemeData(color: s.contains(WidgetState.selected) ? Brand.c500 : muted)),
    ),
    chipTheme: ChipThemeData(
      backgroundColor: dark ? Brand.darkSurfaceVariant : const Color(0xFFECECED),
      labelStyle: TextStyle(color: text, fontSize: 13),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
      side: BorderSide.none,
    ),
    snackBarTheme: SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
    ),
    progressIndicatorTheme: const ProgressIndicatorThemeData(color: Brand.c500),
  );
}
