import 'package:flutter_test/flutter_test.dart';
import 'package:sis_app/api.dart';
import 'package:sis_app/screens/student_home.dart';

void main() {
  test('server addresses are normalised', () {
    expect(Api.normalize('sis.lan'), 'https://sis.lan');
    expect(Api.normalize(' http://192.168.1.10:8080/ '), 'http://192.168.1.10:8080');
    expect(Api.normalize(''), hostedUrl);
  });

  test('amounts are shown in dinars with thousands separators', () {
    expect(money(1500000), '1,500,000 د.ع');
    expect(money(750), '750 د.ع');
  });
}
