// ============================================================
// services/api_service.dart — Appels API REST vers GesNotes
// ============================================================
import 'dart:async';
import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../utils/constants.dart';
import 'notifications_service.dart';

class ApiService {
  static const _tokenKey    = 'udh_auth_token';
  static const _etudiantKey = 'udh_etudiant_data';
  static const _modeKey     = 'udh_mode';     // 'etudiant' ou 'parent'
  static const _parentKey   = 'udh_parent_data';

  /// Appelé quand le serveur refuse le jeton (session expirée) : retour à la connexion.
  static void Function()? onSessionExpiree;

  // ── Token ────────────────────────────────────────────────
  static Future<String?> getToken() async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString(_tokenKey);
    return token;
  }

  static Future<void> saveToken(String token) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_tokenKey, token);
  }

  static Future<void> saveEtudiant(Map<String, dynamic> data) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_etudiantKey, json.encode(data));
  }

  static Future<Map<String, dynamic>?> getCachedEtudiant() async {
    final prefs = await SharedPreferences.getInstance();
    final s = prefs.getString(_etudiantKey);
    if (s == null) return null;
    try { return json.decode(s) as Map<String, dynamic>; } catch(_) { return null; }
  }

  static Future<void> logout() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_tokenKey);
    await prefs.remove(_etudiantKey);
    await prefs.remove(_modeKey);
    await prefs.remove(_parentKey);
  }

  /// Session ouverte avec un compte parent (Espace parent).
  static Future<bool> estParent() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_modeKey) == 'parent';
  }

  static Future<String> _url() async =>
      await estParent() ? AppConstants.apiParentsUrl : AppConstants.apiUrl;

  // ── Headers avec token ───────────────────────────────────
  // Le jeton passe UNIQUEMENT dans l'en-tête Authorization : dans l'adresse
  // (?token=…), il finissait dans les journaux des serveurs et des proxys.
  static Future<Map<String, String>> _headers() async {
    final token = await getToken();
    final headers = <String, String>{
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };
    if (token != null && token.isNotEmpty) {
      headers['Authorization'] = 'Bearer $token';
    }
    return headers;
  }

  static Map<String, dynamic> _erreur(String message) =>
      {'success': false, 'message': message, 'data': null};

  /// Envoie la requête et décode la réponse JSON (en UTF-8 : le serveur
  /// n'indique pas toujours le jeu de caractères, et les accents étaient
  /// alors mal affichés).
  static Future<Map<String, dynamic>> _envoyer(Future<http.Response> Function() requete) async {
    try {
      final res = await requete().timeout(const Duration(seconds: 25));
      final Map<String, dynamic> corps;
      try {
        corps = json.decode(utf8.decode(res.bodyBytes)) as Map<String, dynamic>;
      } catch (_) {
        return _erreur('Réponse inattendue du serveur (${res.statusCode}). Réessayez plus tard.');
      }
      if (res.statusCode == 401 && await isLoggedIn()) {
        await logout();
        onSessionExpiree?.call();
      }
      return corps;
    } on TimeoutException {
      return _erreur('Le serveur ne répond pas. Vérifiez votre connexion et réessayez.');
    } catch (_) {
      return _erreur('Connexion au serveur impossible. Vérifiez votre connexion Internet.');
    }
  }

  // ── GET ──────────────────────────────────────────────────
  static Future<Map<String, dynamic>> get(String action, [Map<String, String>? params]) async {
    final uri = Uri.parse(await _url()).replace(queryParameters: {'action': action, ...?params});
    final headers = await _headers();
    return _envoyer(() => http.get(uri, headers: headers));
  }

  // ── POST ─────────────────────────────────────────────────
  static Future<Map<String, dynamic>> post(String action, Map<String, dynamic> body) async {
    final uri = Uri.parse(await _url()).replace(queryParameters: {'action': action});
    final headers = await _headers();
    return _envoyer(() => http.post(uri, headers: headers, body: json.encode(body)));
  }

  // ── Auth ─────────────────────────────────────────────────
  static Future<Map<String, dynamic>> login(String identifiant, String password, {String? dateNaissance}) async {
    final res = await post('login', {
      'identifiant': identifiant,
      'password': password,
      'device': 'Flutter Android',
      if (dateNaissance != null) 'date_naissance': dateNaissance,
    });
    if (res['success'] == true) {
      final token    = res['data']['token'] as String;
      final etudiant = res['data']['etudiant'] as Map<String, dynamic>;
      await saveToken(token);
      await saveEtudiant(etudiant);
      NotificationsService.enregistrer();
    }
    return res;
  }

  // ── Espace parent ────────────────────────────────────────
  /// Connexion avec un compte de l'Espace Parents (téléphone + mot de passe).
  static Future<Map<String, dynamic>> loginParent(String telephone, String password) async {
    final uri = Uri.parse(AppConstants.apiParentsUrl).replace(queryParameters: {'action': 'login'});
    final res = await _envoyer(() => http.post(uri,
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: json.encode({'telephone': telephone, 'password': password, 'device': 'Flutter Android'})));
    if (res['success'] == true) {
      final prefs = await SharedPreferences.getInstance();
      await saveToken(res['data']['token'] as String);
      await prefs.setString(_modeKey, 'parent');
      await prefs.setString(_parentKey, json.encode(res['data']['parent']));
      NotificationsService.enregistrer();
    }
    return res;
  }

  /// Appel de l'API parents sans session (mot de passe oublié).
  static Future<Map<String, dynamic>> _postParentsPublic(String action, Map<String, dynamic> body) {
    final uri = Uri.parse(AppConstants.apiParentsUrl).replace(queryParameters: {'action': action});
    return _envoyer(() => http.post(uri,
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'}, body: json.encode(body)));
  }

  /// Mot de passe oublié (parent) : code envoyé par SMS au numéro du compte.
  static Future<Map<String, dynamic>> demanderCodeParent(String telephone) =>
      _postParentsPublic('reset_password_demande', {'telephone': telephone});

  static Future<Map<String, dynamic>> reinitialiserMotDePasseParent(String telephone, String code, String nouveau) =>
      _postParentsPublic('reset_password', {'telephone': telephone, 'code': code, 'nouveau_mdp': nouveau});

  static Future<Map<String, dynamic>?> getCachedParent() async {
    final prefs = await SharedPreferences.getInstance();
    final s = prefs.getString(_parentKey);
    if (s == null) return null;
    try { return json.decode(s) as Map<String, dynamic>; } catch(_) { return null; }
  }

  static Future<Map<String, dynamic>> getProfilParent() async {
    final res = await get('profil');
    if (res['success'] == true) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString(_parentKey, json.encode(res['data']));
    }
    return res;
  }

  /// Données d'un enfant : enfant, resultats, presences, paiements, emploi_du_temps, examens, annonces.
  static Future<Map<String, dynamic>> getEnfant(String action, int etudiantId) =>
      get(action, {'et_id': etudiantId.toString()});

  static Future<Map<String, dynamic>> changerMotDePasseParent(String actuel, String nouveau) =>
      post('mot_de_passe', {'actuel': actuel, 'nouveau': nouveau});

  /// Déconnexion parent : le jeton est aussi supprimé sur le serveur.
  static Future<void> logoutParent() async {
    await NotificationsService.retirer();
    await post('logout', {});
    await logout();
  }

  static Future<bool> isLoggedIn() async {
    final token = await getToken();
    return token != null && token.isNotEmpty;
  }

  // ── Mot de passe oublié (code envoyé par SMS ou e-mail) ──
  static Future<Map<String, dynamic>> demanderCodeReinitialisation(String identifiant) =>
      post('reset_password_demande', {'identifiant': identifiant});

  static Future<Map<String, dynamic>> reinitialiserMotDePasse(String identifiant, String code, String nouveau) =>
      post('reset_password', {'identifiant': identifiant, 'code': code, 'nouveau_mdp': nouveau});

  // ── Endpoints ────────────────────────────────────────────
  static Future<Map<String, dynamic>> getProfil()       => get('profil');
  static Future<Map<String, dynamic>> getInscriptions() => get('inscriptions');
  static Future<Map<String, dynamic>> getAnnonces()     => get('annonces');
  static Future<Map<String, dynamic>> getMessages()     => get('messages');
  static Future<Map<String, dynamic>> getStatistiques() => get('statistiques');
  static Future<Map<String, dynamic>> getCarte()        => get('carte');

  static Future<Map<String, dynamic>> getResultats([String? anneeId]) =>
      get('resultats', anneeId != null ? {'annee_id': anneeId} : null);

  static Future<Map<String, dynamic>> envoyerMessage(String sujet, String corps) =>
      post('envoyer_message', {'sujet': sujet, 'corps': corps});

  static Future<void> marquerLu(int id) =>
      post('marquer_lu', {'message_id': id});

  static Future<Map<String, dynamic>> getRecu(int insId) =>
      get('recu', {'ins_id': insId.toString()});

  static Future<Map<String, dynamic>> checkInscription() =>
      get('check_inscription');

  // ── Inscription académique en ligne ──────────────────────
  static Future<Map<String, dynamic>> getInscriptionAcademique() =>
      get('inscription_academique');

  static Future<Map<String, dynamic>> demarrerInscriptionAcademique() =>
      post('inscription_academique_demarrer', {});

  static Future<Map<String, dynamic>> payerInscriptionAcademique(int id, String moyen, String telephone) =>
      post('inscription_academique_payer', {'id': id, 'moyen': moyen, 'telephone': telephone});

  /// Inscription d'un autre étudiant (sans téléphone) : recherche par matricule ou N° dossier.
  static Future<Map<String, dynamic>> inscrireUnAutre(String identifiant) =>
      post('inscription_academique_tiers', {'identifiant': identifiant});

  static Future<Map<String, dynamic>> statutInscriptionAcademique(int id) =>
      get('inscription_academique_statut', {'id': id.toString()});

  // ── Réclamations ─────────────────────────────────────────
  static Future<Map<String, dynamic>> getReclamationsData() =>
      get('reclamations_data');

  static Future<Map<String, dynamic>> getMatieresPv(int pvId) =>
      get('matieres_pv', {'pv_id': pvId.toString()});

  static Future<Map<String, dynamic>> soumettreReclamation({
    required int pvId,
    required int matiereId,
    required String motif,
  }) =>
      post('soumettre_reclamation', {
        'pv_id':      pvId,
        'matiere_id': matiereId,
        'motif':      motif,
      });

  // ── Cache local (mode hors-ligne) ────────────────────────
  static Future<void> cacheData(String key, dynamic data) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('cache_$key', json.encode(data));
      await prefs.setInt('cache_${key}_ts', DateTime.now().millisecondsSinceEpoch);
    } catch(_) {}
  }

  static Future<dynamic> getCached(String key) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final s = prefs.getString('cache_$key');
      return s != null ? json.decode(s) : null;
    } catch(_) { return null; }
  }
}
