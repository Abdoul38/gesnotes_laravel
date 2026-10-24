// ============================================================
// widgets/cloche_notifications.dart — Icône cloche avec le nombre de non lues
// ============================================================
import 'package:flutter/material.dart';
import '../screens/notifications_screen.dart';
import '../services/notifications_service.dart';

class ClocheNotifications extends StatefulWidget {
  final Color couleur;
  const ClocheNotifications({super.key, this.couleur = Colors.white});
  @override
  State<ClocheNotifications> createState() => _ClocheNotificationsState();
}

class _ClocheNotificationsState extends State<ClocheNotifications> {
  @override
  void initState() {
    super.initState();
    NotificationsService.actualiserCompteur();
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<int>(
      valueListenable: NotificationsService.nonLues,
      builder: (_, n, __) => IconButton(
        tooltip: 'Notifications',
        icon: Badge(
          isLabelVisible: n > 0,
          label: Text(n > 99 ? '99+' : '$n', style: const TextStyle(fontSize: 10, color: Colors.white)),
          child: Icon(n > 0 ? Icons.notifications_active : Icons.notifications_none, color: widget.couleur),
        ),
        onPressed: () async {
          await Navigator.push(context, MaterialPageRoute(builder: (_) => const NotificationsScreen()));
          NotificationsService.actualiserCompteur();
        },
      ),
    );
  }
}
