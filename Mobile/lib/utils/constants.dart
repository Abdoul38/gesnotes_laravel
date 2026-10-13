// ============================================================
// utils/constants.dart — Constantes globales UDH Mobile
// ============================================================
import 'package:flutter/material.dart';

class AppConstants {
  // ── API ────────────────────────────────────────────────────
  // Modifier selon votre configuration réseau
  static const String apiUrl = 'https://edufile.alwaysdata.net/api_mobile.php';
  // Pour test local (même appareil) :
  //static const String apiUrl = 'http://172.18.10.36/gesnotes/api_mobile.php';

  static const String appName = 'UDH Mobile';
  static const String appVersion = '1.0.0';
  static const String universityName = 'Université Djibo Hamani';
  static const String universitySubtitle = 'Service Central de la Scolarité';

  // ── Couleurs UDH ───────────────────────────────────────────
  static const Color primaryColor   = Color(0xFF1A3A5C);  // Bleu marine UDH
  static const Color secondaryColor = Color(0xFF5B21B6);  // Violet
  static const Color accentColor    = Color(0xFF16A34A);  // Vert
  static const Color warningColor   = Color(0xFFF59E0B);  // Amber
  static const Color errorColor     = Color(0xFFDC2626);  // Rouge
  static const Color bgColor        = Color(0xFFF8FAFC);  // Fond gris clair

  // ── Thème ──────────────────────────────────────────────────
  static ThemeData get theme => ThemeData(
    useMaterial3: true,
    colorScheme: ColorScheme.fromSeed(
      seedColor: primaryColor,
      primary: primaryColor,
      secondary: secondaryColor,
      surface: Colors.white,
      surfaceContainerHighest: bgColor,
    ),
    appBarTheme: const AppBarTheme(
      backgroundColor: primaryColor,
      foregroundColor: Colors.white,
      elevation: 0,
      centerTitle: true,
      titleTextStyle: TextStyle(
        color: Colors.white,
        fontSize: 18,
        fontWeight: FontWeight.w700,
        letterSpacing: .3,
      ),
    ),
    cardTheme: const CardThemeData(
      elevation: 2,
      shadowColor: Color(0x1F000000),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.all(Radius.circular(12)),
      ),
      color: Colors.white,
    ),
    elevatedButtonTheme: ElevatedButtonThemeData(
      style: ElevatedButton.styleFrom(
        backgroundColor: primaryColor,
        foregroundColor: Colors.white,
        elevation: 0,
        padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 24),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        textStyle: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: Colors.grey.shade50,
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: BorderSide(color: const Color(0xFFD1D5DB)),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: BorderSide(color: Colors.grey.shade300),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: primaryColor, width: 2),
      ),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
    ),
    scaffoldBackgroundColor: bgColor,
  );
}

// ── Types de badges ────────────────────────────────────────
Color getAnnoncTypeColor(String type) {
  return switch (type) {
    'urgent'    => const Color(0xFFDC2626),
    'important' => const Color(0xFFF59E0B),
    'evenement' => const Color(0xFF16A34A),
    _           => const Color(0xFF3B82F6),
  };
}

String getAnnonceTypeIcon(String type) {
  return switch (type) {
    'urgent'    => '🚨',
    'important' => '⚠️',
    'evenement' => '🎉',
    _           => 'ℹ️',
  };
}

// ── Mention couleur ────────────────────────────────────────
Color getMentionColor(String? mention) {
  return switch (mention?.toLowerCase()) {
    'très bien'   => const Color(0xFF16A34A),
    'bien'        => const Color(0xFF2563EB),
    'assez bien'  => const Color(0xFF7C3AED),
    'passable'    => const Color(0xFFF59E0B),
    _             => const Color(0xFFDC2626),
  };
}