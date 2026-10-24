// ============================================================
// main.dart — Point d'entrée UDH Mobile
// ============================================================
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'services/api_service.dart';
import 'services/notifications_service.dart';
import 'screens/login_screen.dart';
import 'screens/home_screen.dart';
import 'screens/parent_home_screen.dart';
import 'utils/constants.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  SystemChrome.setPreferredOrientations([DeviceOrientation.portraitUp]);
  SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(
    statusBarColor: AppConstants.primaryColor,
    statusBarIconBrightness: Brightness.light,
  ));
  await NotificationsService.initialiser();
  // Session expirée (jeton refusé par le serveur) : retour à l'écran de connexion
  ApiService.onSessionExpiree = () => navigatorKey.currentState?.pushAndRemoveUntil(
        MaterialPageRoute(builder: (_) => const LoginScreen()), (_) => false);
  runApp(const UDHApp());
}

final navigatorKey = GlobalKey<NavigatorState>();

class UDHApp extends StatelessWidget {
  const UDHApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: AppConstants.appName,
      navigatorKey: navigatorKey,
      scaffoldMessengerKey: NotificationsService.messagerKey,
      theme: AppConstants.theme,
      debugShowCheckedModeBanner: false,
      home: const SplashScreen(),
    );
  }
}

// ── Splash Screen ─────────────────────────────────────────────
class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});
  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> with SingleTickerProviderStateMixin {
  late AnimationController _ctrl;
  late Animation<double> _fade;

  @override
  void initState() {
    super.initState();
    _ctrl = AnimationController(vsync: this, duration: const Duration(milliseconds: 800));
    _fade = Tween<double>(begin: 0, end: 1).animate(CurvedAnimation(parent: _ctrl, curve: Curves.easeIn));
    _ctrl.forward();
    _init();
  }

  Future<void> _init() async {
    await Future.delayed(const Duration(seconds: 2));
    if (!mounted) return;
    final loggedIn = await ApiService.isLoggedIn();
    final parent = loggedIn && await ApiService.estParent();
    if (loggedIn) NotificationsService.enregistrer();
    if (!mounted) return;
    Navigator.pushReplacement(context,
      MaterialPageRoute(builder: (_) => !loggedIn ? const LoginScreen() : (parent ? const ParentHomeScreen() : const HomeScreen())),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppConstants.primaryColor,
      body: FadeTransition(
        opacity: _fade,
        child: Center(
          child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
            Container(
              width: 100, height: 100,
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(24),
                boxShadow: [BoxShadow(color: Colors.black26, blurRadius: 20, spreadRadius: 2)],
              ),
              child: const Center(child: Text('UDH', style: TextStyle(fontSize: 28, fontWeight: FontWeight.w900, color: AppConstants.primaryColor))),
            ),
            const SizedBox(height: 24),
            const Text(AppConstants.universityName,
              style: TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w800),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 6),
            Text(AppConstants.universitySubtitle,
              style: TextStyle(color: Colors.white.withOpacity(.7), fontSize: 13),
            ),
            const SizedBox(height: 50),
            const CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
          ]),
        ),
      ),
    );
  }

  @override
  void dispose() { _ctrl.dispose(); super.dispose(); }
}
