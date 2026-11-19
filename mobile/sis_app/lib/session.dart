import 'package:material_ui/material_ui.dart';

import 'api.dart';

/// Who is signed in, shared by every screen.
class Session extends ChangeNotifier {
  Map<String, dynamic>? user;
  bool ready = false;

  bool get signedIn => user != null;
  bool get isStudent => user?['is_student'] == true;
  int get unread => (user?['unread'] as num?)?.toInt() ?? 0;

  Future<void> start() async {
    await Api.instance.load();
    if (Api.instance.signedIn) {
      try {
        user = await Api.instance.get('/me');
      } on ApiError catch (e) {
        if (e.status == 401) user = null;
      }
    }
    ready = true;
    notifyListeners();
  }

  Future<void> signedInAs(String token, Map<String, dynamic> account) async {
    await Api.instance.saveToken(token);
    user = account;
    notifyListeners();
  }

  Future<void> refresh() async {
    try {
      user = await Api.instance.get('/me');
    } on ApiError catch (e) {
      if (e.status == 401) user = null;
    }
    notifyListeners();
  }

  void setUnread(int n) {
    if (user != null) user!['unread'] = n;
    notifyListeners();
  }

  Future<void> signOut() async {
    try {
      await Api.instance.post('/auth/logout');
    } catch (_) {}
    await Api.instance.saveToken(null);
    user = null;
    notifyListeners();
  }
}
