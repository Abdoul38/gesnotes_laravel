// ============================================================
// screens/parent_reset_screen.dart — Mot de passe oublié (parent)
// ============================================================
// Étape 1 : numéro du compte → code envoyé par SMS.
// Étape 2 : code reçu + nouveau mot de passe.
import 'package:flutter/material.dart';
import '../services/api_service.dart';
import '../utils/constants.dart';

class ParentResetScreen extends StatefulWidget {
  const ParentResetScreen({super.key});
  @override
  State<ParentResetScreen> createState() => _ParentResetScreenState();
}

class _ParentResetScreenState extends State<ParentResetScreen> {
  final _telCtrl  = TextEditingController();
  final _codeCtrl = TextEditingController();
  final _mdpCtrl  = TextEditingController();
  bool _codeEnvoye = false;
  bool _loading = false;
  String? _erreur;
  String? _info;

  Future<void> _demander() async {
    if (_telCtrl.text.trim().isEmpty) {
      setState(() => _erreur = 'Saisissez le numéro de téléphone de votre compte.');
      return;
    }
    setState(() { _loading = true; _erreur = null; _info = null; });
    final res = await ApiService.demanderCodeParent(_telCtrl.text.trim());
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (res['success'] == true) { _codeEnvoye = true; _info = res['message']; } else { _erreur = res['message']; }
    });
  }

  Future<void> _reinitialiser() async {
    setState(() { _loading = true; _erreur = null; });
    final res = await ApiService.reinitialiserMotDePasseParent(_telCtrl.text.trim(), _codeCtrl.text.trim(), _mdpCtrl.text);
    if (!mounted) return;
    setState(() => _loading = false);
    if (res['success'] == true) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(res['message'] ?? 'Mot de passe changé.')));
      Navigator.pop(context);
    } else {
      setState(() => _erreur = res['message'] ?? 'Erreur');
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Mot de passe oublié')),
      body: SafeArea(
        child: ListView(padding: const EdgeInsets.all(24), children: [
          const Icon(Icons.lock_reset, size: 56, color: AppConstants.primaryColor),
          const SizedBox(height: 12),
          Text(_codeEnvoye ? 'Saisissez le code reçu par SMS et votre nouveau mot de passe.'
              : 'Saisissez le numéro de téléphone de votre compte parent : un code vous sera envoyé par SMS.',
              textAlign: TextAlign.center, style: const TextStyle(fontSize: 14)),
          const SizedBox(height: 20),
          TextField(
            controller: _telCtrl,
            enabled: !_codeEnvoye,
            keyboardType: TextInputType.phone,
            decoration: const InputDecoration(labelText: 'Numéro de téléphone', prefixIcon: Icon(Icons.phone_outlined)),
          ),
          if (_codeEnvoye) ...[
            const SizedBox(height: 14),
            TextField(
              controller: _codeCtrl,
              keyboardType: TextInputType.number,
              maxLength: 6,
              decoration: const InputDecoration(labelText: 'Code reçu par SMS', prefixIcon: Icon(Icons.sms_outlined)),
            ),
            TextField(
              controller: _mdpCtrl,
              obscureText: true,
              decoration: const InputDecoration(labelText: 'Nouveau mot de passe', helperText: '8 caractères, lettres et chiffres',
                  prefixIcon: Icon(Icons.lock_outline)),
            ),
          ],
          if (_info != null) ...[
            const SizedBox(height: 12),
            Text(_info!, style: const TextStyle(color: AppConstants.accentColor, fontSize: 13)),
          ],
          if (_erreur != null) ...[
            const SizedBox(height: 12),
            Text(_erreur!, style: const TextStyle(color: AppConstants.errorColor, fontSize: 13)),
          ],
          const SizedBox(height: 20),
          SizedBox(
            height: 50,
            child: ElevatedButton(
              onPressed: _loading ? null : (_codeEnvoye ? _reinitialiser : _demander),
              child: _loading
                  ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                  : Text(_codeEnvoye ? 'Changer mon mot de passe' : 'Recevoir un code'),
            ),
          ),
          if (_codeEnvoye)
            TextButton(
              onPressed: _loading ? null : () => setState(() { _codeEnvoye = false; _info = null; _erreur = null; }),
              child: const Text('Renvoyer un code / changer de numéro'),
            ),
        ]),
      ),
    );
  }

  @override
  void dispose() { _telCtrl.dispose(); _codeCtrl.dispose(); _mdpCtrl.dispose(); super.dispose(); }
}
