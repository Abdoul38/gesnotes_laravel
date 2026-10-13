// ============================================================
// services/api_service.dart — Appels API REST vers GesNotes
// ============================================================
import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../utils/constants.dart';

class ApiService {
  static const _tokenKey    = 'udh_auth_token';
  static const _etudiantKey = 'udh_etudiant_data';

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
  }

  // ── Headers avec token ───────────────────────────────────
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

  // ── GET ──────────────────────────────────────────────────
  static Future<Map<String, dynamic>> get(String action, [Map<String, String>? params]) async {
    try {
      final token = await getToken();
      // Ajouter le token aussi en paramètre GET (fallback)
      final qParams = <String, String>{'action': action};
      if (token != null && token.isNotEmpty) qParams['token'] = token;
      if (params != null) qParams.addAll(params);

      final uri = Uri.parse(AppConstants.apiUrl).replace(queryParameters: qParams);
      final res = await http.get(uri, headers: await _headers())
          .timeout(const Duration(seconds: 20));
      return json.decode(res.body) as Map<String, dynamic>;
    } catch (e) {
      return {'success': false, 'message': 'Erreur réseau : $e', 'data': null};
    }
  }

  // ── POST ─────────────────────────────────────────────────
  static Future<Map<String, dynamic>> post(String action, Map<String, dynamic> body) async {
    try {
      final token = await getToken();
      final uri = Uri.parse(AppConstants.apiUrl).replace(
        queryParameters: {'action': action, if (token != null) 'token': token},
      );
      final res = await http.post(uri,
        headers: await _headers(),
        body: json.encode(body),
      ).timeout(const Duration(seconds: 20));
      return json.decode(res.body) as Map<String, dynamic>;
    } catch (e) {
      return {'success': false, 'message': 'Erreur réseau : $e', 'data': null};
    }
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
    }
    return res;
  }

  static Future<bool> isLoggedIn() async {
    final token = await getToken();
    return token != null && token.isNotEmpty;
  }

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