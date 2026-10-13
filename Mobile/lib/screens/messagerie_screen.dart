// ============================================================
// screens/messagerie_screen.dart — Messagerie interne
// ============================================================
import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../utils/constants.dart';

class MessagerieSreen extends StatefulWidget {
  final int nonLus;
  final VoidCallback onRead;
  const MessagerieSreen({super.key, required this.nonLus, required this.onRead});
  @override
  State<MessagerieSreen> createState() => _MessagerieScreenState();
}

class _MessagerieScreenState extends State<MessagerieSreen> {
  List<dynamic> _messages = [];
  bool _loading = true;
  final _sujetCtrl = TextEditingController();
  final _corpsCtrl = TextEditingController();

  @override
  void initState() { super.initState(); _load(); }

  Future<void> _load() async {
    setState(() => _loading = true);
    final res = await ApiService.getMessages();
    if (mounted && res['success'] == true) {
      setState(() { _messages = res['data'] ?? []; _loading = false; });
    } else {
      final cached = await ApiService.getCached('messages');
      if (mounted) setState(() { _messages = cached ?? []; _loading = false; });
    }
  }

  Future<void> _marquerLu(Map msg) async {
    if (msg['lu'] == '0' || msg['lu'] == 0) {
      await ApiService.marquerLu(int.tryParse(msg['id'].toString()) ?? 0);
      widget.onRead();
      _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Messagerie')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: Column(children: [
                Expanded(
                  child: _messages.isEmpty
                      ? const Center(child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                          Text('💬', style: TextStyle(fontSize: 48)),
                          SizedBox(height: 12),
                          Text('Aucun message'),
                        ]))
                      : ListView.builder(
                          padding: const EdgeInsets.all(12),
                          itemCount: _messages.length,
                          itemBuilder: (_, i) => _msgCard(_messages[i]),
                        ),
                ),
                // Zone de rédaction
                Container(
                  decoration: BoxDecoration(
                    color: Colors.white,
                    boxShadow: [BoxShadow(color: Colors.black12, blurRadius: 8, offset: const Offset(0,-2))],
                  ),
                  padding: const EdgeInsets.all(12),
                  child: Row(children: [
                    Expanded(
                      child: Column(children: [
                        TextField(
                          controller: _sujetCtrl,
                          decoration: const InputDecoration(hintText: 'Sujet...', isDense: true, border: OutlineInputBorder(), contentPadding: EdgeInsets.symmetric(horizontal: 10, vertical: 8)),
                          style: const TextStyle(fontSize: 13),
                        ),
                        const SizedBox(height: 6),
                        TextField(
                          controller: _corpsCtrl,
                          decoration: const InputDecoration(hintText: 'Votre message à la scolarité...', isDense: true, border: OutlineInputBorder(), contentPadding: EdgeInsets.symmetric(horizontal: 10, vertical: 8)),
                          maxLines: 2,
                          style: const TextStyle(fontSize: 13),
                        ),
                      ]),
                    ),
                    const SizedBox(width: 8),
                    IconButton(
                      icon: const Icon(Icons.send, color: AppConstants.primaryColor),
                      onPressed: _envoyer,
                    ),
                  ]),
                ),
              ]),
            ),
    );
  }

  Widget _msgCard(Map msg) {
    final fromAdmin = msg['expediteur_type'] == 'admin';
    final lu = msg['lu'] == 1 || msg['lu'] == '1';
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      color: lu ? Colors.white : Colors.blue.shade50,
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: fromAdmin ? AppConstants.primaryColor : AppConstants.secondaryColor,
          child: Text(fromAdmin ? 'S' : 'E', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700)),
        ),
        title: Row(children: [
          Expanded(child: Text(msg['sujet'] ?? '—', style: TextStyle(fontWeight: lu ? FontWeight.w600 : FontWeight.w800, fontSize: 14))),
          if (!lu) Container(width: 8, height: 8, decoration: const BoxDecoration(color: AppConstants.secondaryColor, shape: BoxShape.circle)),
        ]),
        subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(msg['expediteur'] ?? '—', style: TextStyle(fontSize: 11, color: Colors.grey.shade600)),
          Text(
            (msg['corps'] ?? '').length > 60 ? '${(msg['corps'] ?? '').substring(0, 60)}...' : (msg['corps'] ?? ''),
            style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
          ),
        ]),
        onTap: () {
          _marquerLu(msg);
          _showMessage(msg);
        },
      ),
    );
  }

  void _showMessage(Map msg) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => DraggableScrollableSheet(
        initialChildSize: .6,
        expand: false,
        builder: (_, ctrl) => SingleChildScrollView(
          controller: ctrl,
          padding: const EdgeInsets.all(24),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Center(child: Container(width: 40, height: 4, decoration: BoxDecoration(color: Colors.grey.shade300, borderRadius: BorderRadius.circular(2)))),
            const SizedBox(height: 16),
            Text(msg['sujet'] ?? '', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: AppConstants.primaryColor)),
            const SizedBox(height: 4),
            Text('De : ${msg['expediteur'] ?? '—'}', style: TextStyle(fontSize: 12, color: Colors.grey.shade500)),
            const Divider(height: 24),
            Text(msg['corps'] ?? '', style: const TextStyle(fontSize: 14, height: 1.7)),
          ]),
        ),
      ),
    );
  }

  Future<void> _envoyer() async {
    final sujet = _sujetCtrl.text.trim();
    final corps = _corpsCtrl.text.trim();
    if (sujet.isEmpty || corps.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Sujet et message requis')));
      return;
    }
    final res = await ApiService.envoyerMessage(sujet, corps);
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(res['message'] ?? ''),
      backgroundColor: res['success'] == true ? AppConstants.accentColor : AppConstants.errorColor,
    ));
    if (res['success'] == true) { _sujetCtrl.clear(); _corpsCtrl.clear(); _load(); }
  }

  @override
  void dispose() { _sujetCtrl.dispose(); _corpsCtrl.dispose(); super.dispose(); }
}
