import 'dart:io' show Platform;

import 'package:material_ui/material_ui.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:webview_flutter/webview_flutter.dart';

import '../api.dart';

/// Opens a page of the site already signed in: inside the app on phones, in the browser on Windows.
/// PDFs always go to the system viewer.
Future<void> openSite(BuildContext context, String path, {String? title, bool external = false}) async {
  final messenger = ScaffoldMessenger.of(context);
  final navigator = Navigator.of(context);
  try {
    final url = await Api.instance.webLink(path);
    final outside = external || path.endsWith('.pdf') || !(Platform.isAndroid || Platform.isIOS);
    if (outside) {
      if (!await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication)) {
        messenger.showSnackBar(const SnackBar(content: Text('تعذّر فتح المتصفح.')));
      }
      return;
    }
    navigator.push(MaterialPageRoute(builder: (_) => SitePage(url: url, title: title ?? 'نظام معلومات الطلبة')));
  } on ApiError catch (e) {
    messenger.showSnackBar(SnackBar(content: Text(e.message)));
  }
}

class SitePage extends StatefulWidget {
  const SitePage({super.key, required this.url, required this.title, this.embedded = false});
  final String url;
  final String title;
  final bool embedded;

  @override
  State<SitePage> createState() => SitePageState();
}

class SitePageState extends State<SitePage> {
  late final WebViewController controller;
  int progress = 0;

  @override
  void initState() {
    super.initState();
    final origin = Uri.parse(Api.instance.server);
    controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setNavigationDelegate(NavigationDelegate(
        onProgress: (p) => setState(() => progress = p),
        onNavigationRequest: (req) {
          final u = Uri.parse(req.url);
          // PDFs and other sites (payment gateways' apps, etc.) open outside.
          if (u.host != origin.host || u.path.endsWith('.pdf')) {
            launchUrl(u, mode: LaunchMode.externalApplication);
            return NavigationDecision.prevent;
          }
          return NavigationDecision.navigate;
        },
      ))
      ..loadRequest(Uri.parse(widget.url));
  }

  Future<bool> back() async {
    if (await controller.canGoBack()) {
      await controller.goBack();
      return true;
    }
    return false;
  }

  @override
  Widget build(BuildContext context) {
    final body = Column(children: [
      if (progress < 100) LinearProgressIndicator(value: progress / 100, minHeight: 2),
      Expanded(child: WebViewWidget(controller: controller)),
    ]);
    if (widget.embedded) return body;
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) return;
        if (!await back() && context.mounted) Navigator.of(context).pop();
      },
      child: Scaffold(
        appBar: AppBar(title: Text(widget.title), actions: [IconButton(onPressed: () => controller.reload(), icon: const Icon(Icons.refresh))]),
        body: body,
      ),
    );
  }
}
