// ============================================================
// screens/recu_screen.dart — Reçu d'inscription mobile
// ============================================================
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import '../services/api_service.dart';
import '../utils/constants.dart';

class RecuScreen extends StatefulWidget {
  final dynamic inscriptionId;
  const RecuScreen({super.key, required this.inscriptionId});

  @override
  State<RecuScreen> createState() => _RecuScreenState();
}

class _RecuScreenState extends State<RecuScreen> {
  Map<String, dynamic>? _recu;
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    final id = int.tryParse(widget.inscriptionId.toString()) ?? 0;
    final res = await ApiService.getRecu(id);
    if (!mounted) return;
    if (res['success'] == true) {
      setState(() { _recu = res['data']; _loading = false; });
    } else {
      setState(() { _error = res['message']; _loading = false; });
    }
  }

  String _fmt(String? d) {
    if (d == null || d.isEmpty) return '—';
    try {
      final dt = DateTime.parse(d);
      return '${dt.day.toString().padLeft(2,'0')}/${dt.month.toString().padLeft(2,'0')}/${dt.year} à ${dt.hour.toString().padLeft(2,'0')}:${dt.minute.toString().padLeft(2,'0')}';
    } catch (_) { return d; }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Reçu d\'inscription'),
        actions: [
          if (_recu != null)
            IconButton(
              icon: const Icon(Icons.open_in_browser),
              tooltip: 'Ouvrir dans le navigateur',
              onPressed: _ouvrirPortail,
            ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? _buildError()
              : _buildRecu(),
    );
  }

  Widget _buildError() {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
          const Text('❌', style: TextStyle(fontSize: 48)),
          const SizedBox(height: 16),
          Text(_error!, textAlign: TextAlign.center, style: TextStyle(color: Colors.grey.shade600)),
          const SizedBox(height: 20),
          ElevatedButton.icon(
            onPressed: _load,
            icon: const Icon(Icons.refresh),
            label: const Text('Réessayer'),
          ),
        ]),
      ),
    );
  }

  Widget _buildRecu() {
    final r = _recu!;
    final montant = int.tryParse(r['montant']?.toString() ?? '0') ?? 0;
    final montantFmt = montant.toString().replaceAllMapped(RegExp(r'(\d)(?=(\d{3})+(?!\d))'), (m) => '${m[1]} ');

    final moyenLabels = {
      'mobile_money': 'Mobile Money',
      'mynita':       'MyNITA',
      'amana':        'Amana Microfinance',
      'visa_master':  'Visa/Mastercard',
    };

    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(children: [

        // En-tête UDH
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            gradient: const LinearGradient(
              colors: [AppConstants.primaryColor, Color(0xFF2C5F9E)],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
            borderRadius: BorderRadius.circular(16),
          ),
          child: Column(children: [
            const Text('UNIVERSITÉ DJIBO HAMANI', style: TextStyle(color: Colors.white, fontSize: 14, fontWeight: FontWeight.w800, letterSpacing: .5)),
            const SizedBox(height: 2),
            const Text('Service Central de la Scolarité', style: TextStyle(color: Colors.white70, fontSize: 11)),
            const SizedBox(height: 16),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
              decoration: BoxDecoration(color: Colors.white24, borderRadius: BorderRadius.circular(20)),
              child: const Text('REÇU D\'INSCRIPTION', style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w900, letterSpacing: 1.5)),
            ),
            const SizedBox(height: 6),
            Text(r['annee'] ?? '—', style: const TextStyle(color: Colors.white70, fontSize: 12)),
          ]),
        ),

        const SizedBox(height: 16),

        // Statut + Matricule
        if (r['matricule'] != null && r['matricule'] != '') ...[
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: AppConstants.accentColor.withOpacity(.1),
              border: Border.all(color: AppConstants.accentColor.withOpacity(.4)),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Column(children: [
              const Text('✅ Inscription validée', style: TextStyle(color: AppConstants.accentColor, fontWeight: FontWeight.w700, fontSize: 14)),
              const SizedBox(height: 6),
              const Text('Votre matricule', style: TextStyle(color: AppConstants.accentColor, fontSize: 12)),
              Text(
                r['matricule'],
                style: const TextStyle(color: AppConstants.primaryColor, fontSize: 32, fontWeight: FontWeight.w900, letterSpacing: 4, fontFamily: 'monospace'),
              ),
            ]),
          ),
          const SizedBox(height: 14),
        ],

        // Infos formation
        _section('🎓 Formation inscrite', [
          _row('Filière',  r['filiere']      ?? '—'),
          _row('Niveau',   r['niveau']       ?? '—'),
          _row('Faculté',  r['faculte']      ?? '—'),
          _row('Année',    r['annee']        ?? '—'),
        ]),

        const SizedBox(height: 12),

        // Détails paiement
        _section('💳 Détails du paiement', [
          _row('Date',         _fmt(r['date_paiement'])),
          if (r['numero_dossier'] != null && r['numero_dossier'] != '')
            _row('N° Dossier', r['numero_dossier']),
          _row('Mode',         moyenLabels[r['moyen_paiement']] ?? r['moyen_paiement'] ?? '—'),
          if (r['reference_paiement'] != null)
            _row('Référence',  r['reference_paiement']),
          _row('N° Reçu',      r['recu_numero'] ?? '—', mono: true),
        ]),

        const SizedBox(height: 12),

        // Montant
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(18),
          decoration: BoxDecoration(
            color: const Color(0xFFF0FDF4),
            border: Border.all(color: const Color(0xFF86EFAC)),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            const Text('Montant payé', style: TextStyle(color: AppConstants.accentColor, fontWeight: FontWeight.w600, fontSize: 14)),
            Text('$montantFmt FCFA', style: const TextStyle(color: AppConstants.accentColor, fontWeight: FontWeight.w900, fontSize: 22)),
          ]),
        ),

        const SizedBox(height: 16),

        // Note importante
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: const Color(0xFFFFFBEB),
            border: Border.all(color: const Color(0xFFFBBF24)),
            borderRadius: BorderRadius.circular(10),
          ),
          child: const Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('⚠️ IMPORTANT', style: TextStyle(color: Color(0xFF92400E), fontWeight: FontWeight.w800, fontSize: 12)),
            SizedBox(height: 6),
            Text(
              'Ce reçu confirme votre inscription administrative. Présentez-le à la Scolarité centrale pour finaliser votre inscription académique. Conservez-le précieusement.',
              style: TextStyle(color: Color(0xFF92400E), fontSize: 12, height: 1.5),
            ),
          ]),
        ),

        const SizedBox(height: 20),

        // Bouton imprimer
        SizedBox(
          width: double.infinity,
          child: ElevatedButton.icon(
            onPressed: _ouvrirPortail,
            icon: const Icon(Icons.print),
            label: const Text('Imprimer / Télécharger PDF'),
          ),
        ),

        const SizedBox(height: 8),

        Text(
          'Université Djibo Hamani — Service Central de la Scolarité\n${r['recu_numero'] ?? ''}',
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 10, color: Colors.grey.shade400),
        ),
        const SizedBox(height: 20),
      ]),
    );
  }

  Widget _section(String title, List<Widget> rows) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(title, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w800, color: AppConstants.primaryColor)),
          const Divider(height: 16),
          ...rows,
        ]),
      ),
    );
  }

  Widget _row(String label, String value, {bool mono = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SizedBox(
          width: 110,
          child: Text(label, style: TextStyle(fontSize: 13, color: Colors.grey.shade600)),
        ),
        Expanded(
          child: Text(
            value,
            style: TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w600,
              color: AppConstants.primaryColor,
              fontFamily: mono ? 'monospace' : null,
            ),
            textAlign: TextAlign.end,
          ),
        ),
      ]),
    );
  }

  Future<void> _ouvrirPortail() async {
    final base = AppConstants.apiUrl.replaceAll('/api_mobile.php', '');
    final insId = widget.inscriptionId;
    final url = Uri.parse('$base/portail2/index.php?page=recu&ins_id=$insId');
    if (await canLaunchUrl(url)) {
      await launchUrl(url, mode: LaunchMode.externalApplication);
    }
  }
}
