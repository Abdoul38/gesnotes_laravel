// ============================================================
// screens/notifications_screen.dart — La cloche : liste des notifications
// ============================================================
import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../services/notifications_service.dart';
import '../utils/constants.dart';

class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});
  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  List<dynamic> _elements = [];
  bool _chargement = true;
  String? _erreur;

  static const _categories = {
    'resultats': (Icons.bar_chart, Color(0xFF2563EB)),
    'absence':   (Icons.event_busy, Color(0xFFDC2626)),
    'examen':    (Icons.event, Color(0xFF7C3AED)),
    'paiement':  (Icons.payments_outlined, Color(0xFFF59E0B)),
    'message':   (Icons.mail_outline, Color(0xFF0891B2)),
    'annonce':   (Icons.campaign_outlined, Color(0xFF16A34A)),
  };

  @override
  void initState() { super.initState(); _charger(); }

  Future<void> _charger() async {
    setState(() { _chargement = true; _erreur = null; });
    final res = await ApiService.getNotifications();
    if (!mounted) return;
    if (res['success'] == true) {
      setState(() { _elements = (res['data']['elements'] as List?) ?? []; _chargement = false; });
      // Liste ouverte : tout devient lu (le badge de la cloche repasse à zéro)
      await ApiService.marquerNotificationsLues();
      NotificationsService.nonLues.value = 0;
    } else {
      setState(() { _chargement = false; _erreur = res['message'] ?? 'Notifications indisponibles.'; });
    }
  }

  String _quand(String? brut) {
    final d = DateTime.tryParse(brut ?? '');
    if (d == null) return '';
    final ecart = DateTime.now().difference(d);
    if (ecart.inMinutes < 1) return 'à l\'instant';
    if (ecart.inMinutes < 60) return 'il y a ${ecart.inMinutes} min';
    if (ecart.inHours < 24) return 'il y a ${ecart.inHours} h';
    if (ecart.inDays < 7) return 'il y a ${ecart.inDays} j';
    return '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}/${d.year}';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Notifications'), actions: [IconButton(icon: const Icon(Icons.refresh), onPressed: _charger)]),
      body: _chargement
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _charger,
              child: _erreur != null
                  ? ListView(children: [Padding(padding: const EdgeInsets.all(32), child: Text(_erreur!, textAlign: TextAlign.center))])
                  : _elements.isEmpty
                      ? ListView(children: const [
                          SizedBox(height: 120),
                          Icon(Icons.notifications_none, size: 56, color: Colors.grey),
                          SizedBox(height: 10),
                          Text('Aucune notification pour le moment.', textAlign: TextAlign.center, style: TextStyle(color: Colors.grey)),
                        ])
                      : ListView.separated(
                          padding: const EdgeInsets.symmetric(vertical: 8),
                          itemCount: _elements.length,
                          separatorBuilder: (_, __) => const Divider(height: 1, indent: 72),
                          itemBuilder: (_, i) {
                            final n = _elements[i] as Map<String, dynamic>;
                            final c = _categories[n['categorie']] ?? (Icons.notifications_outlined, AppConstants.primaryColor);
                            final nonLue = n['lue'] != true;
                            return Container(
                              color: nonLue ? AppConstants.primaryColor.withOpacity(.05) : null,
                              child: ListTile(
                                leading: CircleAvatar(backgroundColor: c.$2.withOpacity(.12), child: Icon(c.$1, color: c.$2)),
                                title: Text(n['titre'] ?? '', style: TextStyle(fontWeight: nonLue ? FontWeight.w800 : FontWeight.w600)),
                                subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                  const SizedBox(height: 2),
                                  Text(n['texte'] ?? ''),
                                  const SizedBox(height: 4),
                                  Text(_quand(n['date']), style: const TextStyle(fontSize: 11, color: Colors.grey)),
                                ]),
                                trailing: nonLue ? const Icon(Icons.circle, size: 10, color: AppConstants.secondaryColor) : null,
                                isThreeLine: true,
                              ),
                            );
                          },
                        ),
            ),
    );
  }
}
