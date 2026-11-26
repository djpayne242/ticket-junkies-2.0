// Run after "npx cap add android" / "npx cap add ios": adds camera access (door scanner), app version and store settings.
// Safe to run more than once.
import fs from 'node:fs';
const pkg = JSON.parse(fs.readFileSync('package.json', 'utf8'));
const versionName = process.env.APP_VERSION || pkg.version;
const versionCode = Number(process.env.APP_BUILD || 0) || null;

function edit(file, fn) {
  if (!fs.existsSync(file)) { console.log('skip (not added yet):', file); return; }
  const before = fs.readFileSync(file, 'utf8');
  const after = fn(before);
  if (after !== before) { fs.writeFileSync(file, after); console.log('updated', file); } else console.log('ok', file);
}

// ---------- Android ----------
edit('android/app/src/main/AndroidManifest.xml', (s) => {
  const add = [
    '<uses-permission android:name="android.permission.CAMERA" />',
    '<uses-feature android:name="android.hardware.camera" android:required="false" />',
    '<uses-permission android:name="android.permission.VIBRATE" />',
  ].filter((l) => !s.includes(l.split(' />')[0]));
  return add.length ? s.replace('<application', add.join('\n    ') + '\n\n    <application') : s;
});
edit('android/app/build.gradle', (s) => {
  s = s.replace(/versionName "[^"]*"/, `versionName "${versionName}"`);
  if (versionCode) s = s.replace(/versionCode \d+/, `versionCode ${versionCode}`);
  return s;
});

// ---------- iPhone ----------
edit('ios/App/App/Info.plist', (s) => {
  const keys = {
    NSCameraUsageDescription: 'TicketJunkies uses the camera to scan ticket QR codes at the door.',
    NSPhotoLibraryAddUsageDescription: 'Save your tickets and event flyers to your photos.',
    NSPhotoLibraryUsageDescription: 'Choose a flyer image for your event.',
  };
  for (const [k, v] of Object.entries(keys)) if (!s.includes(`<key>${k}</key>`)) s = s.replace(/<\/dict>\s*<\/plist>\s*$/, `\t<key>${k}</key>\n\t<string>${v}</string>\n</dict>\n</plist>\n`);
  if (!s.includes('<key>ITSAppUsesNonExemptEncryption</key>')) s = s.replace(/<\/dict>\s*<\/plist>\s*$/, '\t<key>ITSAppUsesNonExemptEncryption</key>\n\t<false/>\n</dict>\n</plist>\n');
  return s;
});
edit('ios/App/App.xcodeproj/project.pbxproj', (s) => {
  s = s.replace(/MARKETING_VERSION = [^;]+;/g, `MARKETING_VERSION = ${versionName};`);
  if (versionCode) s = s.replace(/CURRENT_PROJECT_VERSION = [^;]+;/g, `CURRENT_PROJECT_VERSION = ${versionCode};`);
  return s;
});
