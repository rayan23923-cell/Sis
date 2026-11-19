; Inno Setup script for the SIS Windows app (built by .github/workflows/mobile-apps.yml).
;   flutter build windows --release --dart-define=SITE_URL=https://sis.azoclinic.duckdns.org
;   ISCC.exe windows\installer\setup.iss /DMyAppVersion=1.0.1
#ifndef MyAppVersion
  #define MyAppVersion "1.0.0"
#endif

#define MyAppName "SIS"
#define MyAppExeName "sis_app.exe"
#define MyAppPublisher "SIS"
; Fixed GUID: keep it the same between releases so a new version upgrades in place.
#define MyAppId "{8C3D1F2A-5B7E-4A90-9E6C-2F4B8D1A7C35}"

[Setup]
AppId={{#MyAppId}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={autopf}\SIS
DefaultGroupName={#MyAppName}
UninstallDisplayIcon={app}\{#MyAppExeName}
OutputDir=..\..\build\windows\installer
OutputBaseFilename=SisSetup-{#MyAppVersion}
SetupIconFile=..\runner\resources\app_icon.ico
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
DisableWelcomePage=yes
CloseApplications=yes
RestartApplications=yes

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Create a &desktop shortcut"; GroupDescription: "Additional shortcuts:"

[Files]
Source: "..\..\build\windows\x64\runner\Release\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"
Name: "{autodesktop}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"; Tasks: desktopicon

[Run]
Filename: "{app}\{#MyAppExeName}"; Description: "Launch {#MyAppName}"; Flags: nowait postinstall
