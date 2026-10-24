// ============================================================
// screens/parent_home_screen.dart — Espace parent : suivi des enfants
// ============================================================
// Le parent choisit l'enfant (s'il en a plusieurs) ; chaque onglet affiche
// les données de cet enfant, en lecture seule.
import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../utils/constants.dart';
import 'annonces_screen.dart';
import 'login_screen.dart';
import 'resultats_screen.dart';
import '../widgets/cloche_notifications.dart';

class ParentHomeScreen extends StatefulWidget {
  const ParentHomeScreen({super.key});
  @override
  State<ParentHomeScreen> createState() => _ParentHomeScreenState();
}

class _ParentHomeScreenState extends State<ParentHomeScreen> {
  Map<String, dynamic>? _parent;
  int? _enfantId;
  int _index = 0;

  List<dynamic> get _enfants => (_parent?['enfants'] as List?) ?? [];
  Map<String, dynamic>? get _enfant =>
      _enfants.cast<Map<String, dynamic>?>().firstWhere((e) => e?['id'] == _enfantId, orElse: () => null);

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final cache = await ApiService.getCachedParent();
    if (mounted && cache != null) _choisir(cache);
    final res = await ApiService.getProfilParent();
    if (mounted && res['success'] == true) _choisir(res['data'] as Map<String, dynamic>);
  }

  void _choisir(Map<String, dynamic> parent) {
    setState(() {
      _parent = parent;
      final ids = _enfants.map((e) => e['id']).toList();
      if (!ids.contains(_enfantId)) _enfantId = ids.isNotEmpty ? ids.first as int : null;
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_parent == null) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    final id = _enfantId;
    if (id == null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Espace parent'), actions: [_boutonDeconnexion()]),
        body: const Center(child: Padding(
          padding: EdgeInsets.all(32),
          child: Text('Aucun étudiant n\'est encore relié à votre compte.\nContactez la scolarité de l\'université.',
              textAlign: TextAlign.center),
        )),
      );
    }
    final nom = '${_enfant?['prenoms'] ?? ''} ${_enfant?['nom'] ?? ''}'.trim();
    final pages = [
      _AccueilParent(key: ValueKey('a$id'), etudiantId: id, enfants: _enfants, onChoix: (v) => setState(() => _enfantId = v),
          parent: _parent!, onDeconnexion: _deconnecter),
      ResultatsScreen(key: ValueKey('r$id'), etudiantId: id, titre: 'Résultats — $nom'),
      _PresencesParent(key: ValueKey('p$id'), etudiantId: id, nom: nom),
      _PlanningParent(key: ValueKey('pl$id'), etudiantId: id, nom: nom),
      _PlusParent(key: ValueKey('m$id'), etudiantId: id, nom: nom, onDeconnexion: _deconnecter),
    ];
    return Scaffold(
      body: IndexedStack(index: _index, children: pages),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (i) => setState(() => _index = i),
        backgroundColor: Colors.white,
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        destinations: const [
          NavigationDestination(icon: Icon(Icons.home_outlined), selectedIcon: Icon(Icons.home), label: 'Accueil'),
          NavigationDestination(icon: Icon(Icons.bar_chart_outlined), selectedIcon: Icon(Icons.bar_chart), label: 'Résultats'),
          NavigationDestination(icon: Icon(Icons.fact_check_outlined), selectedIcon: Icon(Icons.fact_check), label: 'Présences'),
          NavigationDestination(icon: Icon(Icons.calendar_month_outlined), selectedIcon: Icon(Icons.calendar_month), label: 'Planning'),
          NavigationDestination(icon: Icon(Icons.more_horiz), label: 'Plus'),
        ],
      ),
    );
  }

  Widget _boutonDeconnexion() => IconButton(icon: const Icon(Icons.logout), tooltip: 'Déconnexion', onPressed: _deconnecter);

  Future<void> _deconnecter() async {
    await ApiService.logoutParent();
    if (!mounted) return;
    Navigator.pushAndRemoveUntil(context, MaterialPageRoute(builder: (_) => const LoginScreen()), (_) => false);
  }
}

// ── Outils d'affichage ───────────────────────────────────────

String _montant(dynamic v) {
  final n = double.tryParse(v?.toString() ?? '') ?? 0;
  final s = n.toStringAsFixed(0).replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => ' ');
  return '$s FCFA';
}

String _date(dynamic v) {
  final d = DateTime.tryParse(v?.toString() ?? '');
  if (d == null) return '—';
  return '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}/${d.year}';
}

String _heure(dynamic v) => (v?.toString() ?? '').length >= 5 ? v.toString().substring(0, 5) : '—';

/// Chargement d'une donnée de l'enfant, avec cache hors ligne.
mixin _Chargement<T extends StatefulWidget> on State<T> {
  dynamic donnees;
  bool chargement = true;
  bool horsLigne = false;

  Future<void> charger(String action, int etudiantId) async {
    setState(() => chargement = true);
    final cle = '${action}_enfant_$etudiantId';
    final res = await ApiService.getEnfant(action, etudiantId);
    if (!mounted) return;
    if (res['success'] == true) {
      setState(() { donnees = res['data']; chargement = false; horsLigne = false; });
      await ApiService.cacheData(cle, res['data']);
    } else {
      final cache = await ApiService.getCached(cle);
      if (!mounted) return;
      setState(() { donnees = cache; chargement = false; horsLigne = true; });
    }
  }

  List<Widget> actionsHorsLigne(VoidCallback rafraichir) => [
        if (horsLigne) const Padding(padding: EdgeInsets.only(right: 4), child: Icon(Icons.wifi_off, color: Colors.orange)),
        IconButton(icon: const Icon(Icons.refresh), onPressed: rafraichir),
      ];
}

Widget _vide(String emoji, String texte) => Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Text(emoji, style: const TextStyle(fontSize: 44)),
          const SizedBox(height: 10),
          Text(texte, textAlign: TextAlign.center, style: const TextStyle(color: Colors.grey)),
        ]),
      ),
    );

// ── Accueil : l'enfant en un coup d'œil ──────────────────────

class _AccueilParent extends StatefulWidget {
  final int etudiantId;
  final List<dynamic> enfants;
  final ValueChanged<int> onChoix;
  final Map<String, dynamic> parent;
  final VoidCallback onDeconnexion;
  const _AccueilParent({super.key, required this.etudiantId, required this.enfants, required this.onChoix,
      required this.parent, required this.onDeconnexion});
  @override
  State<_AccueilParent> createState() => _AccueilParentState();
}

class _AccueilParentState extends State<_AccueilParent> with _Chargement {
  @override
  void initState() { super.initState(); charger('enfant', widget.etudiantId); }

  @override
  Widget build(BuildContext context) {
    final d = donnees as Map<String, dynamic>?;
    return Scaffold(
      appBar: AppBar(
        title: Text('Bonjour ${widget.parent['prenoms'] ?? ''}'),
        actions: [...actionsHorsLigne(() => charger('enfant', widget.etudiantId)), const ClocheNotifications()],
      ),
      body: chargement && d == null
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: () => charger('enfant', widget.etudiantId),
              child: ListView(padding: const EdgeInsets.all(16), children: [
                if (widget.enfants.length > 1) ...[
                  DropdownButtonFormField<int>(
                    initialValue: widget.etudiantId,
                    // Toute la largeur, noms longs abrégés (sinon débordement à droite)
                    isExpanded: true,
                    decoration: const InputDecoration(labelText: 'Enfant suivi', prefixIcon: Icon(Icons.swap_horiz)),
                    items: widget.enfants.map((e) => DropdownMenuItem<int>(
                        value: e['id'] as int,
                        child: Text('${e['prenoms']} ${e['nom']}', maxLines: 1, overflow: TextOverflow.ellipsis))).toList(),
                    onChanged: (v) { if (v != null) widget.onChoix(v); },
                  ),
                  const SizedBox(height: 12),
                ],
                if (d == null) _vide('📡', 'Données indisponibles. Vérifiez votre connexion.') else ..._contenu(d),
              ]),
            ),
    );
  }

  List<Widget> _contenu(Map<String, dynamic> d) {
    final et = d['etudiant'] as Map<String, dynamic>? ?? {};
    final ins = d['inscription'] as Map<String, dynamic>?;
    final res = d['dernier_resultat'] as Map<String, dynamic>?;
    final examens = (d['prochains_examens'] as List?) ?? [];
    final photo = et['photo_url'] as String?;
    final taux = d['taux_presence'];
    final reste = double.tryParse(d['reste_a_payer']?.toString() ?? '') ?? 0;
    final moyenne = double.tryParse(res?['moyenne_generale']?.toString() ?? '');
    return [
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(children: [
            CircleAvatar(
              radius: 30,
              backgroundColor: AppConstants.primaryColor,
              backgroundImage: photo != null ? NetworkImage(photo) : null,
              onBackgroundImageError: photo != null ? (_, __) {} : null,
              child: photo == null
                  ? Text('${(et['prenoms'] ?? ' ').toString()[0]}${(et['nom'] ?? ' ').toString()[0]}',
                      style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800))
                  : null,
            ),
            const SizedBox(width: 14),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${et['prenoms'] ?? ''} ${et['nom'] ?? ''}',
                  style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: AppConstants.primaryColor)),
              Text('Matricule : ${et['numero'] ?? '—'}', style: const TextStyle(fontSize: 12, color: Colors.grey)),
              if (ins != null)
                Text('${ins['filiere'] ?? ''} · ${ins['niveau'] ?? ''} · ${ins['annee'] ?? ''}',
                    style: const TextStyle(fontSize: 12.5)),
            ])),
          ]),
        ),
      ),
      const SizedBox(height: 8),
      Row(children: [
        Expanded(child: _tuile('Dernière moyenne', moyenne != null ? '${moyenne.toStringAsFixed(2)}/20' : '—',
            res != null ? '${res['semestre'] ?? ''}${res['mention'] != null ? ' · ${res['mention']}' : ''}' : 'Aucun résultat publié',
            moyenne == null ? Colors.grey : (moyenne >= 10 ? AppConstants.accentColor : AppConstants.errorColor))),
        const SizedBox(width: 8),
        Expanded(child: _tuile('Présence', taux != null ? '$taux %' : '—', '${d['absences'] ?? 0} absence(s) récente(s)',
            taux == null ? Colors.grey : (taux >= 75 ? AppConstants.accentColor : AppConstants.warningColor))),
      ]),
      const SizedBox(height: 8),
      Row(children: [
        Expanded(child: _tuile('Reste à payer', _montant(reste), reste > 0 ? 'Frais non soldés' : 'À jour',
            reste > 0 ? AppConstants.warningColor : AppConstants.accentColor)),
        const SizedBox(width: 8),
        Expanded(child: _tuile('Annonces', '${d['nb_annonces'] ?? 0}', 'en cours', AppConstants.secondaryColor)),
      ]),
      const SizedBox(height: 16),
      const Text('📅 Prochains examens', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: AppConstants.primaryColor)),
      const SizedBox(height: 8),
      if (examens.isEmpty)
        const Card(child: Padding(padding: EdgeInsets.all(16), child: Text('Aucun examen planifié pour le moment.', style: TextStyle(color: Colors.grey))))
      else
        ...examens.map((x) => Card(
              child: ListTile(
                leading: const Icon(Icons.event, color: AppConstants.primaryColor),
                title: Text(x['matiere'] ?? '—', style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text('${_date(x['date_examen'])} · ${_heure(x['heure_debut'])}–${_heure(x['heure_fin'])}'
                    '${x['salle'] != null ? ' · ${x['salle']}' : ''}'),
              ),
            )),
    ];
  }

  Widget _tuile(String titre, String valeur, String detail, Color couleur) => Card(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(titre, style: const TextStyle(fontSize: 12, color: Colors.grey)),
            const SizedBox(height: 4),
            Text(valeur, style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900, color: couleur)),
            const SizedBox(height: 2),
            Text(detail, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 11)),
          ]),
        ),
      );
}

// ── Présences ────────────────────────────────────────────────

class _PresencesParent extends StatefulWidget {
  final int etudiantId;
  final String nom;
  const _PresencesParent({super.key, required this.etudiantId, required this.nom});
  @override
  State<_PresencesParent> createState() => _PresencesParentState();
}

class _PresencesParentState extends State<_PresencesParent> with _Chargement {
  static const _statuts = {
    'present': ('Présent', AppConstants.accentColor, Icons.check_circle),
    'absent': ('Absent', AppConstants.errorColor, Icons.cancel),
    'retard': ('En retard', AppConstants.warningColor, Icons.schedule),
    'excuse': ('Excusé', Colors.blueGrey, Icons.info),
  };

  @override
  void initState() { super.initState(); charger('presences', widget.etudiantId); }

  @override
  Widget build(BuildContext context) {
    final d = donnees as Map<String, dynamic>?;
    final liste = (d?['presences'] as List?) ?? [];
    return Scaffold(
      appBar: AppBar(title: Text('Présences — ${widget.nom}'), actions: actionsHorsLigne(() => charger('presences', widget.etudiantId))),
      body: chargement && d == null
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: () => charger('presences', widget.etudiantId),
              child: liste.isEmpty
                  ? ListView(children: [_vide('📋', 'Aucune présence enregistrée.')])
                  : ListView(padding: const EdgeInsets.all(16), children: [
                      Card(
                        color: AppConstants.primaryColor,
                        child: Padding(
                          padding: const EdgeInsets.all(16),
                          child: Row(mainAxisAlignment: MainAxisAlignment.spaceAround, children: [
                            _chiffre('${d?['taux'] ?? '—'} %', 'de présence'),
                            _chiffre('${d?['absences'] ?? 0}', 'absence(s)'),
                            _chiffre('${liste.length}', 'séance(s)'),
                          ]),
                        ),
                      ),
                      const SizedBox(height: 8),
                      ...liste.map((p) {
                        final s = _statuts[p['statut']] ?? ('—', Colors.grey, Icons.help_outline);
                        return Card(
                          child: ListTile(
                            leading: Icon(s.$3, color: s.$2),
                            title: Text(p['matiere'] ?? '—', style: const TextStyle(fontWeight: FontWeight.w700)),
                            subtitle: Text('${_date(p['date_seance'])} · ${p['type_seance'] ?? ''}'
                                '${(p['motif'] ?? '').toString().isNotEmpty ? '\nMotif : ${p['motif']}' : ''}'),
                            trailing: Text(s.$1, style: TextStyle(color: s.$2, fontWeight: FontWeight.w700)),
                          ),
                        );
                      }),
                    ]),
            ),
    );
  }

  Widget _chiffre(String valeur, String libelle) => Column(children: [
        Text(valeur, style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w900)),
        Text(libelle, style: const TextStyle(color: Colors.white70, fontSize: 11)),
      ]);
}

// ── Planning : emploi du temps et examens ────────────────────

class _PlanningParent extends StatefulWidget {
  final int etudiantId;
  final String nom;
  const _PlanningParent({super.key, required this.etudiantId, required this.nom});
  @override
  State<_PlanningParent> createState() => _PlanningParentState();
}

class _PlanningParentState extends State<_PlanningParent> {
  List<dynamic>? _jours;
  List<dynamic>? _examens;
  bool _chargement = true;

  @override
  void initState() { super.initState(); _charger(); }

  Future<void> _charger() async {
    setState(() => _chargement = true);
    final r = await Future.wait([
      ApiService.getEnfant('emploi_du_temps', widget.etudiantId),
      ApiService.getEnfant('examens', widget.etudiantId),
    ]);
    if (!mounted) return;
    setState(() {
      _jours = r[0]['success'] == true ? (r[0]['data'] as List? ?? []) : _jours;
      _examens = r[1]['success'] == true ? (r[1]['data'] as List? ?? []) : _examens;
      _chargement = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: Text('Planning — ${widget.nom}'),
          actions: [IconButton(icon: const Icon(Icons.refresh), onPressed: _charger)],
          bottom: const TabBar(
            labelColor: Colors.white, unselectedLabelColor: Colors.white54, indicatorColor: Colors.white,
            tabs: [Tab(text: 'Emploi du temps'), Tab(text: 'Examens')],
          ),
        ),
        body: _chargement && _jours == null
            ? const Center(child: CircularProgressIndicator())
            : TabBarView(children: [_emploiDuTemps(), _listeExamens()]),
      ),
    );
  }

  Widget _emploiDuTemps() {
    final jours = (_jours ?? []).where((j) => ((j['seances'] as List?) ?? []).isNotEmpty).toList();
    if (jours.isEmpty) return _vide('🗓️', 'Aucun emploi du temps publié pour la promotion.');
    return ListView(padding: const EdgeInsets.all(16), children: [
      for (final j in jours) ...[
        Padding(
          padding: const EdgeInsets.only(top: 8, bottom: 6),
          child: Text(j['jour'], style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: AppConstants.primaryColor)),
        ),
        ...(j['seances'] as List).map((s) => Card(
              child: ListTile(
                leading: Text('${_heure(s['heure_debut'])}\n${_heure(s['heure_fin'])}',
                    textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12)),
                title: Text(s['matiere'] ?? '—', style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text([
                  (s['type_seance'] ?? '').toString().toUpperCase(),
                  if ((s['salle'] ?? '').toString().isNotEmpty) s['salle'],
                  if ((s['enseignant'] ?? '').toString().isNotEmpty) s['enseignant'],
                ].join(' · ')),
              ),
            )),
      ],
    ]);
  }

  Widget _listeExamens() {
    final examens = _examens ?? [];
    if (examens.isEmpty) return _vide('📅', 'Aucun examen planifié pour le moment.');
    return ListView(padding: const EdgeInsets.all(16), children: examens.map((x) {
      final passe = x['passe'] == true;
      return Opacity(
        opacity: passe ? .5 : 1,
        child: Card(
          child: ListTile(
            leading: Icon(passe ? Icons.event_available : Icons.event, color: AppConstants.primaryColor),
            title: Text(x['matiere'] ?? '—', style: const TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text('${_date(x['date_examen'])} · ${_heure(x['heure_debut'])}–${_heure(x['heure_fin'])}'
                '${x['salle'] != null ? ' · ${x['salle']}' : ''}\n${x['semestre'] ?? ''}${x['session'] == 'rattrapage' ? ' · Rattrapage' : ''}'),
          ),
        ),
      );
    }).toList());
  }
}

// ── Plus : paiements, annonces, compte ───────────────────────

class _PlusParent extends StatefulWidget {
  final int etudiantId;
  final String nom;
  final VoidCallback onDeconnexion;
  const _PlusParent({super.key, required this.etudiantId, required this.nom, required this.onDeconnexion});
  @override
  State<_PlusParent> createState() => _PlusParentState();
}

class _PlusParentState extends State<_PlusParent> with _Chargement {
  static const _types = {
    'inscription': 'Inscription', 'scolarite': 'Scolarité', 'rattrapage': 'Rattrapage', 'diplome': 'Diplôme',
    'attestation': 'Attestation', 'certificat': 'Certificat', 'releve_note': 'Relevé de notes', 'depot_dossier': 'Dépôt de dossier', 'autre': 'Autre',
  };

  @override
  void initState() { super.initState(); charger('paiements', widget.etudiantId); }

  @override
  Widget build(BuildContext context) {
    final d = donnees as Map<String, dynamic>?;
    final paiements = (d?['paiements'] as List?) ?? [];
    return Scaffold(
      appBar: AppBar(title: const Text('Plus'), actions: actionsHorsLigne(() => charger('paiements', widget.etudiantId))),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        const Text('💰 Paiements', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: AppConstants.primaryColor)),
        const SizedBox(height: 8),
        if (chargement && d == null)
          const Center(child: Padding(padding: EdgeInsets.all(16), child: CircularProgressIndicator()))
        else ...[
          Card(
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(children: [
                _ligne('Total dû', _montant(d?['total_du'])),
                _ligne('Total payé', _montant(d?['total_paye'])),
                const Divider(),
                _ligne('Reste à payer', _montant(d?['reste']), gras: true),
              ]),
            ),
          ),
          ...paiements.map((p) => Card(
                child: ListTile(
                  leading: const Icon(Icons.receipt_long, color: AppConstants.primaryColor),
                  title: Text(_types[p['type_paiement']] ?? (p['type_paiement'] ?? '—')),
                  subtitle: Text('${_date(p['date_paiement'])}${p['reference'] != null ? ' · Réf. ${p['reference']}' : ''}'),
                  trailing: Text(_montant(p['montant_paye']), style: const TextStyle(fontWeight: FontWeight.w700)),
                ),
              )),
          if (paiements.isEmpty)
            const Card(child: Padding(padding: EdgeInsets.all(16), child: Text('Aucun paiement enregistré.', style: TextStyle(color: Colors.grey)))),
        ],
        const SizedBox(height: 16),
        Card(
          child: Column(children: [
            ListTile(
              leading: const Icon(Icons.campaign, color: AppConstants.primaryColor),
              title: const Text('Annonces de l\'université'),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => AnnoncesScreen(etudiantId: widget.etudiantId))),
            ),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.lock_reset, color: AppConstants.primaryColor),
              title: const Text('Changer mon mot de passe'),
              trailing: const Icon(Icons.chevron_right),
              onTap: _changerMotDePasse,
            ),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.logout, color: AppConstants.errorColor),
              title: const Text('Se déconnecter', style: TextStyle(color: AppConstants.errorColor)),
              onTap: widget.onDeconnexion,
            ),
          ]),
        ),
      ]),
    );
  }

  Widget _ligne(String libelle, String valeur, {bool gras = false}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
          Text(libelle),
          Text(valeur, style: TextStyle(fontWeight: gras ? FontWeight.w900 : FontWeight.w600)),
        ]),
      );

  Future<void> _changerMotDePasse() async {
    final actuel = TextEditingController();
    final nouveau = TextEditingController();
    final message = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Changer mon mot de passe'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: actuel, obscureText: true, decoration: const InputDecoration(labelText: 'Mot de passe actuel')),
          const SizedBox(height: 10),
          TextField(controller: nouveau, obscureText: true,
              decoration: const InputDecoration(labelText: 'Nouveau mot de passe', helperText: '8 caractères, lettres et chiffres')),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Annuler')),
          ElevatedButton(
            onPressed: () async {
              final res = await ApiService.changerMotDePasseParent(actuel.text, nouveau.text);
              if (ctx.mounted) Navigator.pop(ctx, res['message']?.toString() ?? 'Erreur');
            },
            child: const Text('Valider'),
          ),
        ],
      ),
    );
    if (message != null && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
    }
  }
}
