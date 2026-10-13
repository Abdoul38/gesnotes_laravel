// ============================================================
// screens/inscription_academique_screen.dart — Inscription académique
// en ligne de l'étudiant connecté : récapitulatif, paiement (MyNITA,
// Amana, Mobile Money), suivi de la validation, reçu.
// Mêmes règles que le portail web (décision de passage, tarif du niveau,
// frais de transaction, délai de paiement) : tout est vérifié par le serveur.
// ============================================================
import 'dart:async';
import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../utils/constants.dart';
import 'recu_screen.dart';

class InscriptionAcademiqueScreen extends StatefulWidget {
  const InscriptionAcademiqueScreen({super.key});
  @override
  State<InscriptionAcademiqueScreen> createState() => _InscriptionAcademiqueScreenState();
}

class _InscriptionAcademiqueScreenState extends State<InscriptionAcademiqueScreen> {
  Map<String, dynamic>? _etat;
  bool _chargement = true;
  bool _envoi = false;
  String? _erreur;
  String? _info;
  String? _moyen;
  final _telCtrl = TextEditingController();
  Timer? _suivi;
  DateTime? _debutSuivi;

  @override
  void initState() {
    super.initState();
    _charger();
  }

  @override
  void dispose() {
    _suivi?.cancel();
    _telCtrl.dispose();
    super.dispose();
  }

  Map<String, dynamic>? get _enLigne => _etat?['en_ligne'] as Map<String, dynamic>?;

  Map<String, String> get _moyens {
    final m = _etat?['moyens'];
    return m is Map ? m.map((k, v) => MapEntry(k.toString(), v.toString())) : {};
  }

  bool get _paiementEnAttente {
    final e = _enLigne;
    return e != null && e['statut'] != 'complete' && e['statut_paiement'] == 'en_attente'
        && e['moyen_paiement'] != null && e['expire'] != true;
  }

  void _appliquer(Map<String, dynamic> data) {
    _etat = data;
    if (_telCtrl.text.isEmpty) {
      _telCtrl.text = (_enLigne?['telephone_paiement'] ?? data['telephone'] ?? '').toString();
    }
    _moyen ??= _moyens.keys.isNotEmpty ? _moyens.keys.first : null;
    if (_paiementEnAttente) {
      _lancerSuivi();
    } else {
      _suivi?.cancel();
    }
  }

  Future<void> _charger() async {
    setState(() { _chargement = true; _erreur = null; });
    final res = await ApiService.getInscriptionAcademique();
    if (!mounted) return;
    setState(() {
      _chargement = false;
      if (res['success'] == true) {
        _appliquer(res['data'] as Map<String, dynamic>);
      } else {
        _erreur = res['message']?.toString() ?? 'Erreur';
      }
    });
  }

  Future<void> _demarrer() async {
    setState(() { _envoi = true; _erreur = null; });
    final res = await ApiService.demarrerInscriptionAcademique();
    if (!mounted) return;
    setState(() {
      _envoi = false;
      if (res['success'] == true) {
        _appliquer(res['data'] as Map<String, dynamic>);
      } else {
        _erreur = res['message']?.toString();
      }
    });
  }

  Future<void> _payer() async {
    final id = _enLigne?['id'] as int?;
    if (id == null || _moyen == null) return;
    final tel = _telCtrl.text.trim();
    if (!RegExp(r'^\+?[0-9 ]{8,16}$').hasMatch(tel)) {
      setState(() => _erreur = 'Saisissez le numéro de téléphone du compte qui paie (8 chiffres, ou +227…).');
      return;
    }
    final total = _fcfa(_enLigne?['montant']?['total']);
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Confirmer le paiement'),
        content: Text('Payer $total par ${_moyens[_moyen]} depuis le $tel ?\n\n'
            'Vous devrez ensuite valider le paiement sur votre téléphone.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Annuler')),
          ElevatedButton(onPressed: () => Navigator.pop(context, true), child: const Text('Payer')),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    setState(() { _envoi = true; _erreur = null; _info = null; });
    final res = await ApiService.payerInscriptionAcademique(id, _moyen!, tel);
    if (!mounted) return;
    setState(() {
      _envoi = false;
      if (res['success'] == true) {
        _info = res['message']?.toString();
        _appliquer(res['data'] as Map<String, dynamic>);
      } else {
        _erreur = res['message']?.toString();
      }
    });
  }

  /// Vérifie la validation du paiement toutes les 6 secondes, pendant le délai de paiement.
  void _lancerSuivi() {
    if (_suivi?.isActive == true) return;
    _debutSuivi = DateTime.now();
    final limite = Duration(minutes: (_etat?['expiration_minutes'] as int? ?? 15) + 1);
    _suivi = Timer.periodic(const Duration(seconds: 6), (t) {
      if (DateTime.now().difference(_debutSuivi!) > limite) {
        t.cancel();
        _verifier();
        return;
      }
      _verifier(silencieux: true);
    });
  }

  Future<void> _verifier({bool silencieux = false}) async {
    final id = _enLigne?['id'] as int?;
    if (id == null) return;
    if (!silencieux) setState(() => _envoi = true);
    final res = await ApiService.statutInscriptionAcademique(id);
    if (!mounted) return;
    setState(() {
      _envoi = false;
      if (res['success'] == true) {
        final data = res['data'] as Map<String, dynamic>;
        final statut = data['statut'];
        if (statut != 'en_attente' || !silencieux) _info = data['message']?.toString();
        if (statut == 'echec' || statut == 'expire') _erreur = data['message']?.toString();
        _appliquer(data);
      } else if (!silencieux) {
        _erreur = res['message']?.toString();
      }
    });
  }

  // ── Affichage ─────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Inscription académique')),
      body: _chargement
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _charger,
              child: ListView(padding: const EdgeInsets.all(16), children: _contenu()),
            ),
    );
  }

  List<Widget> _contenu() {
    if (_etat == null) {
      return [_message('⚠️', _erreur ?? 'Impossible de charger votre inscription.', bouton: ('Réessayer', _charger))];
    }
    final annee = _etat!['annee'] ?? '—';
    final e = _enLigne;
    final widgets = <Widget>[
      _entete('Année académique $annee'),
      const SizedBox(height: 12),
      if (_erreur != null) _bandeau(_erreur!, AppConstants.errorColor, Icons.error_outline),
      if (_info != null && _erreur == null) _bandeau(_info!, AppConstants.accentColor, Icons.info_outline),
    ];

    // Déjà inscrit (en ligne ou à la scolarité)
    if (_etat!['inscrit'] == true) {
      final insId = _etat!['inscription_id'] as int?;
      return widgets
        ..add(_message('🎉', 'Vous êtes inscrit(e) pour l\'année $annee.'
            '${e?['matricule'] != null ? '\nMatricule : ${e!['matricule']}' : ''}',
            bouton: insId != null ? ('Voir mon reçu', () => Navigator.push(context,
                MaterialPageRoute(builder: (_) => RecuScreen(inscriptionId: insId)))) : null));
    }
    if (_etat!['ouverte'] != true) {
      return widgets..add(_message('🔒', 'Les inscriptions académiques en ligne sont actuellement fermées.\n'
          'Renseignez-vous auprès du service de la scolarité.'));
    }
    if (e == null) {
      return widgets
        ..add(_message('🎓', 'Inscrivez-vous en ligne pour l\'année $annee : vérification de votre '
            'admission, récapitulatif des frais, puis paiement depuis votre téléphone.',
            bouton: ('Commencer mon inscription', _demarrer)));
    }

    widgets.add(_recapitulatif(e));
    widgets.add(const SizedBox(height: 16));
    widgets.add(_paiementEnAttente ? _attente(e) : _formulairePaiement(e));
    return widgets;
  }

  Widget _recapitulatif(Map<String, dynamic> e) {
    final m = (e['montant'] as Map?) ?? {};
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text('Récapitulatif', style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: AppConstants.primaryColor)),
          const SizedBox(height: 10),
          _ligne('Filière', e['filiere'] ?? '—'),
          if ((e['faculte'] ?? '').toString().isNotEmpty) _ligne('Faculté', e['faculte']),
          _ligne('Niveau', '${e['niveau_code'] ?? ''} — ${e['niveau'] ?? ''}'),
          const Divider(height: 20),
          _ligne('Frais d\'inscription', _fcfa(m['base'])),
          _ligne('Frais de transaction', _fcfa(m['frais'])),
          _ligne('Total à payer', _fcfa(m['total']), gras: true),
        ]),
      ),
    );
  }

  Widget _formulairePaiement(Map<String, dynamic> e) {
    if (_moyens.isEmpty) {
      return _message('💳', 'Aucun moyen de paiement n\'est disponible dans l\'application pour le moment.'
          '${_etat!['carte_sur_le_web'] == true ? '\nLe paiement par carte bancaire se fait sur le portail web.' : ''}');
    }
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          const Text('Paiement', style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: AppConstants.primaryColor)),
          const SizedBox(height: 6),
          ..._moyens.entries.map((m) => RadioListTile<String>(
                contentPadding: EdgeInsets.zero,
                value: m.key,
                groupValue: _moyen,
                title: Text(m.value),
                onChanged: _envoi ? null : (v) => setState(() => _moyen = v),
              )),
          const SizedBox(height: 8),
          TextField(
            controller: _telCtrl,
            keyboardType: TextInputType.phone,
            decoration: const InputDecoration(
              labelText: 'Téléphone du compte qui paie',
              hintText: 'Ex : 90 00 00 00',
              prefixIcon: Icon(Icons.phone_android, color: AppConstants.primaryColor),
            ),
          ),
          if (_etat!['carte_sur_le_web'] == true) ...[
            const SizedBox(height: 8),
            Text('Paiement par carte bancaire : sur le portail web uniquement.',
                style: TextStyle(fontSize: 12, color: Colors.grey.shade600)),
          ],
          const SizedBox(height: 16),
          SizedBox(
            height: 50,
            child: ElevatedButton(
              onPressed: _envoi ? null : _payer,
              child: _envoi
                  ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                  : Text('Payer ${_fcfa(e['montant']?['total'])}'),
            ),
          ),
        ]),
      ),
    );
  }

  Widget _attente(Map<String, dynamic> e) {
    final moyen = _moyens[e['moyen_paiement']] ?? e['moyen_paiement'] ?? '';
    return Card(
      color: const Color(0xFFFFFBEB),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          const Row(children: [
            SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)),
            SizedBox(width: 12),
            Expanded(child: Text('Paiement en attente de validation',
                style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: Color(0xFF92400E)))),
          ]),
          const SizedBox(height: 12),
          Text('Validez le paiement de ${_fcfa(e['montant']?['total'])} sur votre compte $moyen '
              '(${e['telephone_paiement'] ?? ''}).'
              '${e['code_achat'] != null ? '\nCode d\'achat : ${e['code_achat']}' : ''}\n\n'
              'Cette page se met à jour toute seule dès que le paiement est validé '
              '(délai : ${_etat!['expiration_minutes'] ?? 15} minutes).',
              style: const TextStyle(fontSize: 13, height: 1.4)),
          const SizedBox(height: 14),
          OutlinedButton.icon(
            onPressed: _envoi ? null : () => _verifier(),
            icon: const Icon(Icons.refresh),
            label: const Text('J\'ai validé : vérifier maintenant'),
          ),
        ]),
      ),
    );
  }

  Widget _entete(String texte) => Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          gradient: const LinearGradient(colors: [AppConstants.primaryColor, Color(0xFF2C5F9E)]),
          borderRadius: BorderRadius.circular(14),
        ),
        child: Row(children: [
          const Text('🎓', style: TextStyle(fontSize: 28)),
          const SizedBox(width: 12),
          Expanded(child: Text(texte, style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w800))),
        ]),
      );

  Widget _bandeau(String texte, Color couleur, IconData icone) => Container(
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: couleur.withOpacity(.08),
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: couleur.withOpacity(.3)),
        ),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(icone, color: couleur, size: 18),
          const SizedBox(width: 8),
          Expanded(child: Text(texte, style: TextStyle(color: couleur, fontSize: 13))),
        ]),
      );

  Widget _message(String icone, String texte, {(String, VoidCallback)? bouton}) => Card(
        child: Padding(
          padding: const EdgeInsets.all(20),
          child: Column(children: [
            Text(icone, style: const TextStyle(fontSize: 40)),
            const SizedBox(height: 12),
            Text(texte, textAlign: TextAlign.center, style: const TextStyle(fontSize: 14, height: 1.4)),
            if (bouton != null) ...[
              const SizedBox(height: 16),
              SizedBox(
                width: double.infinity,
                height: 48,
                child: ElevatedButton(
                  onPressed: _envoi ? null : bouton.$2,
                  child: _envoi
                      ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                      : Text(bouton.$1),
                ),
              ),
            ],
          ]),
        ),
      );

  Widget _ligne(String libelle, dynamic valeur, {bool gras = false}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(children: [
          Expanded(child: Text(libelle, style: TextStyle(fontSize: 13, color: Colors.grey.shade700))),
          Text('$valeur', style: TextStyle(fontSize: gras ? 15 : 13, fontWeight: gras ? FontWeight.w800 : FontWeight.w600,
              color: gras ? AppConstants.accentColor : AppConstants.primaryColor)),
        ]),
      );

  static String _fcfa(dynamic montant) {
    final n = (num.tryParse('${montant ?? 0}') ?? 0).round();
    return '${n.toString().replaceAllMapped(RegExp(r'(\d)(?=(\d{3})+(?!\d))'), (m) => '${m[1]} ')} FCFA';
  }
}
