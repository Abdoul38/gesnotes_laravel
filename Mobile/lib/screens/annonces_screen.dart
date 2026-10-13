// ============================================================
// screens/annonces_screen.dart — Annonces & actualités
// ============================================================
import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../utils/constants.dart';

class AnnoncesScreen extends StatefulWidget {
  const AnnoncesScreen({super.key});
  @override
  State<AnnoncesScreen> createState() => _AnnoncesScreenState();
}

class _AnnoncesScreenState extends State<AnnoncesScreen> {
  List<dynamic> _annonces = [];
  bool _loading = true;
  bool _offline = false;

  @override
  void initState() { super.initState(); _load(); }

  Future<void> _load() async {
    setState(() => _loading = true);
    final res = await ApiService.getAnnonces();
    if (res['success'] == true) {
      setState(() { _annonces = res['data'] ?? []; _loading = false; _offline = false; });
      await ApiService.cacheData('annonces', res['data']);
    } else {
      final cached = await ApiService.getCached('annonces');
      setState(() { _annonces = cached ?? []; _loading = false; _offline = true; });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Annonces & Actualités'),
        actions: [if (_offline) const Padding(padding: EdgeInsets.only(right: 12), child: Icon(Icons.wifi_off, color: Colors.orange))],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: _annonces.isEmpty
                  ? const Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                      Text('📢', style: TextStyle(fontSize: 48)),
                      SizedBox(height: 12),
                      Text('Aucune annonce disponible'),
                    ]))
                  : ListView.builder(
                      padding: const EdgeInsets.all(16),
                      itemCount: _annonces.length,
                      itemBuilder: (_, i) => _annonceCard(_annonces[i]),
                    ),
            ),
    );
  }

  Widget _annonceCard(Map a) {
    final type  = a['type'] ?? 'info';
    final color = getAnnoncTypeColor(type);
    final icon  = getAnnonceTypeIcon(type);
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => _showDetail(a),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          // Header coloré
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
            decoration: BoxDecoration(
              color: color.withOpacity(.1),
              borderRadius: const BorderRadius.vertical(top: Radius.circular(12)),
            ),
            child: Row(children: [
              Text(icon),
              const SizedBox(width: 6),
              Text(type.toUpperCase(), style: TextStyle(fontSize: 10, color: color, fontWeight: FontWeight.w800)),
              const Spacer(),
              if (a['epinglee'] == '1' || a['epinglee'] == 1) const Text('📌', style: TextStyle(fontSize: 12)),
              Text(
                _formatDate(a['created_at']),
                style: TextStyle(fontSize: 10, color: Colors.grey.shade500),
              ),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.all(14),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(a['titre'] ?? '', style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: AppConstants.primaryColor)),
              const SizedBox(height: 6),
              Text(
                (a['contenu'] ?? '').length > 120 ? '${(a['contenu'] ?? '').substring(0, 120)}...' : (a['contenu'] ?? ''),
                style: TextStyle(fontSize: 13, color: Colors.grey.shade700, height: 1.5),
              ),
              const SizedBox(height: 8),
              Text('${a['vues'] ?? 0} vue(s)', style: TextStyle(fontSize: 10, color: Colors.grey.shade400)),
            ]),
          ),
        ]),
      ),
    );
  }

  void _showDetail(Map a) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => DraggableScrollableSheet(
        initialChildSize: .7,
        minChildSize: .4,
        maxChildSize: .95,
        expand: false,
        builder: (_, ctrl) => SingleChildScrollView(
          controller: ctrl,
          padding: const EdgeInsets.all(24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Center(child: Container(width: 40, height: 4, decoration: BoxDecoration(color: Colors.grey.shade300, borderRadius: BorderRadius.circular(2)))),
            const SizedBox(height: 16),
            Text(a['titre'] ?? '', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: AppConstants.primaryColor)),
            const SizedBox(height: 6),
            Text(_formatDate(a['created_at']), style: TextStyle(fontSize: 12, color: Colors.grey.shade500)),
            const Divider(height: 24),
            Text(a['contenu'] ?? '', style: const TextStyle(fontSize: 14, height: 1.7)),
          ]),
        ),
      ),
    );
  }

  String _formatDate(String? d) {
    if (d == null) return '';
    try {
      final dt = DateTime.parse(d);
      return '${dt.day.toString().padLeft(2,'0')}/${dt.month.toString().padLeft(2,'0')}/${dt.year}';
    } catch (_) { return d; }
  }
}
