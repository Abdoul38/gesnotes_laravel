// ============================================================
// screens/dashboard_screen.dart — Tableau de bord étudiant
// ============================================================
import 'package:flutter/material.dart';
import '../utils/constants.dart';
import '../widgets/cloche_notifications.dart';
import '../services/api_service.dart';
import 'inscription_screen.dart';
import 'inscription_academique_screen.dart';
import 'recu_screen.dart';
import 'carte_screen.dart';
import 'resultats_screen.dart';
import 'annonces_screen.dart';
import 'messagerie_screen.dart';
import 'reclamation_screen.dart';

class DashboardScreen extends StatefulWidget {
  final Map<String, dynamic>? etudiant;
  final VoidCallback onRefresh;
  const DashboardScreen({super.key, this.etudiant, required this.onRefresh});
  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  List<dynamic> _inscriptions = [];
  bool _loading = true;

  Map<String, dynamic>? _etudiant;

  @override
  void initState() {
    super.initState();
    _etudiant = widget.etudiant;
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);

    // Rafraîchir les données étudiant depuis l'API
    final profil = await ApiService.getProfil();
    if (mounted && profil['success'] == true) {
      final data = profil['data'] as Map<String, dynamic>;
      await ApiService.saveEtudiant(data);
      setState(() => _etudiant = data);
    }

    // Charger les inscriptions
    final res = await ApiService.getInscriptions();
    if (mounted && res['success'] == true) {
      setState(() { _inscriptions = res['data'] ?? []; _loading = false; });
      await ApiService.cacheData('inscriptions', res['data']);
    } else {
      final cached = await ApiService.getCached('inscriptions');
      if (mounted) setState(() { _inscriptions = cached ?? []; _loading = false; });
    }
  }

  @override
  Widget build(BuildContext context) {
    final et      = _etudiant ?? {};
    final prenom  = et['prenoms']   ?? '';
    final nom     = et['nom']       ?? '';
    final filiere = et['filiere']   ?? '—';
    final mat     = et['numero']    ?? '—';
    final photoUrl= et['photo_url'] as String?;

    return Scaffold(
      backgroundColor: AppConstants.bgColor,
      body: RefreshIndicator(
        onRefresh: () async { await _load(); widget.onRefresh(); },
        child: CustomScrollView(slivers: [

          // AppBar
          SliverAppBar(
            expandedHeight: 190,
            pinned: true,
            actions: const [ClocheNotifications()],
            backgroundColor: AppConstants.primaryColor,
            flexibleSpace: FlexibleSpaceBar(
              background: Container(
                decoration: const BoxDecoration(
                  gradient: LinearGradient(
                    colors: [AppConstants.primaryColor, Color(0xFF2C5F9E)],
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                  ),
                ),
                padding: const EdgeInsets.fromLTRB(20, 60, 20, 16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    CircleAvatar(
                      radius: 30,
                      backgroundColor: Colors.white24,
                      backgroundImage: photoUrl != null ? NetworkImage(photoUrl) : null,
                      onBackgroundImageError: photoUrl != null ? (_, __) {} : null,
                      child: photoUrl == null
                          ? Text(
                              (prenom.isNotEmpty ? prenom[0] : '') + (nom.isNotEmpty ? nom[0] : ''),
                              style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800),
                            )
                          : null,
                    ),
                    const SizedBox(width: 14),
                    Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text('Bonjour, $prenom !', style: const TextStyle(color: Colors.white70, fontSize: 13)),
                      Text(nom.toUpperCase(), style: const TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.w800)),
                      Text('Matricule : $mat', style: const TextStyle(color: Colors.white60, fontSize: 12, fontFamily: 'monospace')),
                    ])),
                  ]),
                  const SizedBox(height: 10),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(color: Colors.white24, borderRadius: BorderRadius.circular(20)),
                    child: Text('📚 $filiere', style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w600)),
                  ),
                ]),
              ),
            ),
          ),

          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [

                // Actions rapides
                _sectionTitle('⚡ Actions rapides'),
                const SizedBox(height: 12),
                GridView.count(
                  crossAxisCount: 3,
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  crossAxisSpacing: 10,
                  mainAxisSpacing: 10,
                  childAspectRatio: 1,
                  children: [
                    _actionCard('📊', 'Résultats', () => Navigator.push(context,
                        MaterialPageRoute(builder: (_) => const ResultatsScreen()))),
                    _actionCard('🪪', 'Ma Carte', () => Navigator.push(context,
                        MaterialPageRoute(builder: (_) => const CarteScreen()))),
                    // Inscription académique de l'année (paiement en ligne) ; candidature : portail des nouveaux bacheliers
                    _actionCard('🎓', 'Inscription académique', () async {
                      await Navigator.push(context, MaterialPageRoute(builder: (_) => const InscriptionAcademiqueScreen()));
                      _load();
                    }),
                    _actionCard('📝', 'Candidature', () => Navigator.push(context,
                        MaterialPageRoute(builder: (_) => const InscriptionScreen()))),
                    _actionCard('📢', 'Annonces', () => Navigator.push(context,
                        MaterialPageRoute(builder: (_) => const AnnoncesScreen()))),
                    _actionCard('💬', 'Messages', () => Navigator.push(context,
                        MaterialPageRoute(builder: (_) => MessagerieSreen(nonLus: 0, onRead: () {})))),
                    _actionCard('📨', 'Réclamation', () => Navigator.push(context,
                        MaterialPageRoute(builder: (_) => const ReclamationScreen()))),
                  ],
                ),

                const SizedBox(height: 20),

                // Inscriptions
                _sectionTitle('🎓 Mes inscriptions'),
                const SizedBox(height: 12),
                if (_loading) const Center(child: CircularProgressIndicator()),
                if (!_loading && _inscriptions.isEmpty) _emptyCard('Aucune inscription trouvée'),
                ..._inscriptions.map((ins) => _inscriptionCard(ins)),

              ]),
            ),
          ),
        ]),
      ),
    );
  }

  Widget _sectionTitle(String t) => Text(t,
    style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: AppConstants.primaryColor));

  Widget _actionCard(String icon, String label, VoidCallback onTap) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(12),
          boxShadow: [BoxShadow(color: Colors.black.withOpacity(.06), blurRadius: 8)],
        ),
        child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
          Text(icon, style: const TextStyle(fontSize: 28)),
          const SizedBox(height: 6),
          Text(label, textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: AppConstants.primaryColor)),
        ]),
      ),
    );
  }

  Widget _inscriptionCard(Map ins) {
    final actif = ins['active'] == 1 || ins['active'] == '1';
    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(children: [
          Container(
            width: 44, height: 44,
            decoration: BoxDecoration(
              color: actif ? AppConstants.accentColor.withOpacity(.1) : Colors.grey.shade100,
              borderRadius: BorderRadius.circular(10),
            ),
            child: Center(child: Text(ins['niveau_code'] ?? '?',
              style: TextStyle(fontWeight: FontWeight.w800,
                color: actif ? AppConstants.accentColor : Colors.grey))),
          ),
          const SizedBox(width: 12),
          Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(ins['annee'] ?? '—', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
            Text('${ins['niveau_lib'] ?? ''} — ${ins['statut'] ?? ''}',
              style: TextStyle(fontSize: 12, color: Colors.grey.shade600)),
            if (ins['montant_paye'] != null)
              Text('${ins['montant_paye']} FCFA',
                style: const TextStyle(fontSize: 12, color: AppConstants.accentColor, fontWeight: FontWeight.w600)),
          ])),
          if (actif) Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
            decoration: BoxDecoration(
              color: AppConstants.accentColor.withOpacity(.1),
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Text('En cours',
              style: TextStyle(fontSize: 10, color: AppConstants.accentColor, fontWeight: FontWeight.w700)),
          ),
          IconButton(
            icon: const Icon(Icons.receipt_outlined, color: AppConstants.primaryColor),
            onPressed: () => Navigator.push(context,
              MaterialPageRoute(builder: (_) => RecuScreen(inscriptionId: ins['id']))),
          ),
        ]),
      ),
    );
  }

  Widget _emptyCard(String msg) => Container(
    padding: const EdgeInsets.all(24),
    alignment: Alignment.center,
    child: Column(children: [
      const Text('📭', style: TextStyle(fontSize: 36)),
      const SizedBox(height: 8),
      Text(msg, style: TextStyle(color: Colors.grey.shade500, fontSize: 13)),
    ]),
  );
}