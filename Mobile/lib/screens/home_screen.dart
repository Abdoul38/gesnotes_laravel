// ============================================================
// screens/home_screen.dart — Écran principal avec BottomNav
// ============================================================
import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../utils/constants.dart';
import 'dashboard_screen.dart';
import 'resultats_screen.dart';
import 'annonces_screen.dart';
import 'messagerie_screen.dart';
import 'profil_screen.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});
  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _index = 0;
  int _msgNonLus = 0;
  Map<String, dynamic>? _etudiant;

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  Future<void> _loadData() async {
    // Charger étudiant depuis cache
    final et = await ApiService.getCachedEtudiant();
    if (mounted) setState(() => _etudiant = et);

    // Rafraîchir depuis l'API
    final res = await ApiService.getProfil();
    if (mounted && res['success'] == true) {
      final etData = res['data'] as Map<String, dynamic>;
      await ApiService.saveEtudiant(etData);
      setState(() => _etudiant = etData);
    }

    // Stats messages non lus
    final stats = await ApiService.getStatistiques();
    if (mounted && stats['success'] == true) {
      setState(() => _msgNonLus = stats['data']['messages_non_lus'] ?? 0);
    }
  }

  @override
  Widget build(BuildContext context) {
    final screens = [
      DashboardScreen(etudiant: _etudiant, onRefresh: _loadData),
      const ResultatsScreen(),
      const AnnoncesScreen(),
      MessagerieSreen(nonLus: _msgNonLus, onRead: () => setState(() => _msgNonLus = 0)),
      ProfilScreen(etudiant: _etudiant),
    ];

    // Avatar photo ou initiales pour l'AppBar
    final prenom   = _etudiant?['prenoms'] ?? '';
    final nom      = _etudiant?['nom']     ?? '';
    final photoUrl = _etudiant?['photo_url'] as String?;
    final initials = (prenom.isNotEmpty ? prenom[0] : '') +
                     (nom.isNotEmpty    ? nom[0]    : '');

    return Scaffold(
      body: IndexedStack(index: _index, children: screens),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (i) => setState(() => _index = i),
        backgroundColor: Colors.white,
        elevation: 8,
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        destinations: [
          NavigationDestination(
            icon: _buildAvatar(photoUrl, initials, selected: false),
            selectedIcon: _buildAvatar(photoUrl, initials, selected: true),
            label: 'Accueil',
          ),
          const NavigationDestination(
            icon: Icon(Icons.bar_chart_outlined),
            selectedIcon: Icon(Icons.bar_chart),
            label: 'Résultats',
          ),
          const NavigationDestination(
            icon: Icon(Icons.campaign_outlined),
            selectedIcon: Icon(Icons.campaign),
            label: 'Annonces',
          ),
          NavigationDestination(
            icon: _msgNonLus > 0
                ? Badge(
                    label: Text('$_msgNonLus',
                        style: const TextStyle(fontSize: 10, color: Colors.white)),
                    backgroundColor: AppConstants.secondaryColor,
                    child: const Icon(Icons.message_outlined),
                  )
                : const Icon(Icons.message_outlined),
            selectedIcon: _msgNonLus > 0
                ? Badge(
                    label: Text('$_msgNonLus',
                        style: const TextStyle(fontSize: 10, color: Colors.white)),
                    backgroundColor: AppConstants.secondaryColor,
                    child: const Icon(Icons.message),
                  )
                : const Icon(Icons.message),
            label: 'Messages',
          ),
          const NavigationDestination(
            icon: Icon(Icons.person_outline),
            selectedIcon: Icon(Icons.person),
            label: 'Profil',
          ),
        ],
      ),
    );
  }

  Widget _buildAvatar(String? photoUrl, String initials, {required bool selected}) {
    return CircleAvatar(
      radius: 14,
      backgroundColor: selected
          ? AppConstants.primaryColor
          : Colors.grey.shade300,
      backgroundImage: photoUrl != null ? NetworkImage(photoUrl) : null,
      onBackgroundImageError: photoUrl != null ? (_, __) {} : null,
      child: photoUrl == null
          ? Text(initials.toUpperCase(),
              style: TextStyle(
                fontSize: 10,
                fontWeight: FontWeight.w800,
                color: selected ? Colors.white : Colors.grey.shade600,
              ))
          : null,
    );
  }
}