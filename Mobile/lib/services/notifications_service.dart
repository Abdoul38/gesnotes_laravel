// ============================================================
// services/notifications_service.dart — Notifications sur le téléphone
// ============================================================
// Firebase Cloud Messaging : après la connexion (étudiant ou parent), le
// jeton du téléphone est envoyé au serveur, qui s'en sert pour prévenir des
// résultats publiés, absences, examens du lendemain et paiements en retard.
// Application fermée : Android affiche la notification ; application
// ouverte : un bandeau en bas de l'écran.
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'api_service.dart';

class NotificationsService {
  static bool _pret = false;

  /// Bandeau affiché quand une notification arrive application ouverte.
  static final messagerKey = GlobalKey<ScaffoldMessengerState>();

  /// Nombre de notifications non lues (badge de la cloche).
  static final nonLues = ValueNotifier<int>(0);

  static Future<void> actualiserCompteur() async {
    if (!await ApiService.isLoggedIn()) return;
    final res = await ApiService.getNotifications();
    if (res['success'] == true) nonLues.value = (res['data']['non_lues'] as num?)?.toInt() ?? 0;
  }

  static Future<void> initialiser() async {
    try {
      await Firebase.initializeApp();
      _pret = true;
      FirebaseMessaging.onMessage.listen((message) {
        final n = message.notification;
        if (n == null) return;
        actualiserCompteur();
        messagerKey.currentState?.showSnackBar(SnackBar(
          content: Text('${n.title ?? ''}\n${n.body ?? ''}'.trim()),
          duration: const Duration(seconds: 6),
        ));
      });
      // Application ouverte depuis une notification : badge à jour
      FirebaseMessaging.onMessageOpenedApp.listen((_) => actualiserCompteur());
      FirebaseMessaging.instance.onTokenRefresh.listen((jeton) async {
        if (await ApiService.isLoggedIn()) _envoyer(jeton);
      });
    } catch (_) {
      _pret = false; // Firebase indisponible : l'application fonctionne sans notifications
    }
  }

  /// À appeler une fois connecté : autorisation (Android 13+), puis jeton envoyé au serveur.
  static Future<void> enregistrer() async {
    if (!_pret) return;
    try {
      await FirebaseMessaging.instance.requestPermission();
      final jeton = await FirebaseMessaging.instance.getToken();
      if (jeton != null) await _envoyer(jeton);
    } catch (_) {}
  }

  /// Avant la déconnexion : le téléphone ne reçoit plus les notifications de ce compte.
  static Future<void> retirer() async {
    nonLues.value = 0;
    if (!_pret) return;
    try {
      final jeton = await FirebaseMessaging.instance.getToken();
      if (jeton != null) await ApiService.post('push_token', {'token': jeton, 'retirer': true});
    } catch (_) {}
  }

  static Future<void> _envoyer(String jeton) =>
      ApiService.post('push_token', {'token': jeton, 'plateforme': 'android'});
}
