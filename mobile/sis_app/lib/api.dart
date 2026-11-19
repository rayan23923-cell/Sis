import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// The hosted site, set at build time; the university's own (offline) server is chosen in the app.
const hostedUrl = String.fromEnvironment('SITE_URL', defaultValue: 'https://sis.azoclinic.duckdns.org');
const appVersion = String.fromEnvironment('APP_VERSION', defaultValue: '1.0.0');

class ApiError implements Exception {
  ApiError(this.message, {this.status});
  final String message;
  final int? status;
  @override
  String toString() => message;
}

/// Talks to /api/v1 of the chosen server with the saved sign-in token.
class Api {
  Api._();
  static final Api instance = Api._();

  final _secure = const FlutterSecureStorage();
  final Dio _dio = Dio(BaseOptions(
    connectTimeout: const Duration(seconds: 15),
    receiveTimeout: const Duration(seconds: 30),
    headers: {'Accept': 'application/json'},
  ));

  String server = hostedUrl;
  String? _token;
  bool get signedIn => _token != null;

  Future<void> load() async {
    final prefs = await SharedPreferences.getInstance();
    server = normalize(prefs.getString('server') ?? hostedUrl);
    _token = await _secure.read(key: 'token:$server');
  }

  static String normalize(String input) {
    var s = input.trim();
    if (s.isEmpty) return hostedUrl;
    if (!s.startsWith('http://') && !s.startsWith('https://')) s = 'https://$s';
    while (s.endsWith('/')) {
      s = s.substring(0, s.length - 1);
    }
    return s;
  }

  /// Checks that [url] is an SIS server and keeps it.
  Future<Map<String, dynamic>> useServer(String url) async {
    final candidate = normalize(url);
    final info = await _request('GET', '/info', base: candidate, auth: false);
    if (info['system'] != 'sis') throw ApiError('هذا العنوان ليس خادم نظام معلومات الطلبة.');
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('server', candidate);
    server = candidate;
    _token = await _secure.read(key: 'token:$server');
    return info;
  }

  Future<void> saveToken(String? token) async {
    _token = token;
    if (token == null) {
      await _secure.delete(key: 'token:$server');
    } else {
      await _secure.write(key: 'token:$server', value: token);
    }
  }

  Future<Map<String, dynamic>> get(String path) => _request('GET', path);
  Future<Map<String, dynamic>> post(String path, [Map<String, dynamic>? data]) => _request('POST', path, data: data);

  /// A one-time link that opens the site already signed in (valid for one minute).
  Future<String> webLink([String next = '']) async {
    final r = await post('/web-link', {'next': next});
    return r['url'] as String;
  }

  Future<Map<String, dynamic>> _request(String method, String path, {Map<String, dynamic>? data, String? base, bool auth = true}) async {
    try {
      final r = await _dio.request<dynamic>(
        '${base ?? server}/api/v1$path',
        data: data,
        options: Options(method: method, headers: {if (auth && _token != null) 'Authorization': 'Bearer $_token'}),
      );
      final body = r.data;
      return body is Map<String, dynamic> ? body : <String, dynamic>{};
    } on DioException catch (e) {
      final status = e.response?.statusCode;
      final body = e.response?.data;
      if (status == 401 && auth) await saveToken(null);
      String? message;
      if (body is Map) {
        final errors = body['errors'];
        if (errors is Map && errors.isNotEmpty) {
          final first = errors.values.first;
          message = first is List && first.isNotEmpty ? '${first.first}' : '$first';
        }
        message ??= body['message'] as String?;
      }
      if (e.type == DioExceptionType.connectionTimeout || e.type == DioExceptionType.connectionError || e.type == DioExceptionType.unknown) {
        message = 'تعذّر الوصول إلى الخادم. تأكد من الاتصال بالشبكة أو من عنوان الخادم.';
      }
      throw ApiError(message ?? 'حدث خطأ غير متوقع (${status ?? '—'}).', status: status);
    }
  }
}
