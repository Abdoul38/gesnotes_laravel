// ============================================================
// screens/profil_screen.dart — Profil étudiant
// ============================================================
import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../utils/constants.dart';
import 'login_screen.dart';

class ProfilScreen extends StatelessWidget {
  final Map<String, dynamic>? etudiant;
  const ProfilScreen({super.key, this.etudiant});

  @override
  Widget build(BuildContext context) {
    final et       = etudiant ?? {};
    final prenom   = et['prenoms'] ?? '';
    final nom      = et['nom']     ?? '';
    final photoUrl = et['photo_url'] as String?;
    final initials = (prenom.isNotEmpty ? prenom[0] : '') +
                     (nom.isNotEmpty    ? nom[0]    : '');

    return Scaffold(
      appBar: AppBar(title: const Text('Mon Profil')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(children: [

          // Avatar
          CircleAvatar(
            radius: 50,
            backgroundColor: AppConstants.primaryColor,
            backgroundImage: photoUrl != null ? NetworkImage(photoUrl) : null,
            onBackgroundImageError: photoUrl != null ? (_, __) {} : null,
            child: photoUrl == null
                ? Text(initials.toUpperCase(),
                    style: const TextStyle(color: Colors.white, fontSize: 36, fontWeight: FontWeight.w800))
                : null,
          ),
          const SizedBox(height: 12),
          Text('$prenom $nom',
            style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800,
                color: AppConstants.primaryColor)),
          Text('Matricule : ${et['numero'] ?? '—'}',
            style: TextStyle(color: Colors.grey.shade600, fontFamily: 'monospace')),
          const SizedBox(height: 24),

          // Infos académiques
          _infoCard([
            _infoRow('📚 Filière',   et['filiere']  ?? '—'),
            _infoRow('🎓 Faculté',   et['faculte']  ?? '—'),
            _infoRow('👤 Sexe',      et['sexe'] == 'M' ? 'Masculin' : 'Féminin'),
            _infoRow('🗓️ Date naiss.', et['date_naissance']  ?? '—'),
            _infoRow('📍 Lieu naiss.', et['lieu_naissance']  ?? '—'),
            _infoRow('🌍 Nationalité', et['nationalite']     ?? '—'),
          ]),

          const SizedBox(height: 12),

          // Contacts
          _infoCard([
            _infoRow('📱 Téléphone', et['telephone'] ?? '—'),
            _infoRow('✉️ Email',     et['email']     ?? '—'),
            _infoRow('🏠 Adresse',   et['adresse']   ?? '—'),
          ]),

          const SizedBox(height: 12),

          // BAC
          _infoCard([
            _infoRow('🎓 Série BAC',      et['bac_serie']             ?? '—'),
            _infoRow('📅 Année BAC',      et['bac_annee']             ?? '—'),
            _infoRow('🏆 Mention BAC',    et['bac_mention']           ?? '—'),
            _infoRow('🏫 Établissement',  et['etablissement_origine'] ?? '—'),
          ]),

          const SizedBox(height: 24),

          // Déconnexion
          SizedBox(
            width: double.infinity,
            child: ElevatedButton.icon(
              onPressed: () async {
                final ok = await showDialog<bool>(
                  context: context,
                  builder: (_) => AlertDialog(
                    title: const Text('Déconnexion'),
                    content: const Text('Voulez-vous vous déconnecter ?'),
                    actions: [
                      TextButton(onPressed: () => Navigator.pop(context, false),
                          child: const Text('Annuler')),
                      ElevatedButton(onPressed: () => Navigator.pop(context, true),
                          child: const Text('Déconnecter')),
                    ],
                  ),
                );
                if (ok == true && context.mounted) {
                  await ApiService.logout();
                  Navigator.pushAndRemoveUntil(context,
                    MaterialPageRoute(builder: (_) => const LoginScreen()),
                    (_) => false);
                }
              },
              icon: const Icon(Icons.logout),
              label: const Text('Se déconnecter'),
              style: ElevatedButton.styleFrom(backgroundColor: AppConstants.errorColor),
            ),
          ),

          const SizedBox(height: 20),
          Text('UDH Mobile v${AppConstants.appVersion}',
            style: TextStyle(fontSize: 12, color: Colors.grey.shade400)),
          const SizedBox(height: 20),
        ]),
      ),
    );
  }

  Widget _infoCard(List<Widget> rows) => Card(
    child: Padding(
      padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 4),
      child: Column(children: rows),
    ),
  );

  Widget _infoRow(String label, String value) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
    child: Row(children: [
      Text(label, style: TextStyle(fontSize: 13, color: Colors.grey.shade600)),
      const Spacer(),
      Flexible(child: Text(value,
        style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600,
            color: AppConstants.primaryColor),
        textAlign: TextAlign.end)),
    ]),
  );
}