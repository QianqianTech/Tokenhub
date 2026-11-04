// Copies the TokenHub secure-store plugin into a generated Capacitor platform
// project and wires it up (run by `npm run add:ios` / `npm run add:android`
// right after `cap add`). Idempotent.
import { copyFileSync, existsSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";
import xcode from "xcode";

const platform = process.argv[2];
const root = fileURLToPath(new URL("..", import.meta.url));

function edit(file, change) {
  const before = readFileSync(file, "utf8");
  const after = change(before);
  if (after !== before) {
    writeFileSync(file, after);
  }
}

if (platform === "ios") {
  const app = join(root, "ios/App/App");
  copyFileSync(join(root, "plugins/ios/TokenHubSecureStorePlugin.swift"), join(app, "TokenHubSecureStorePlugin.swift"));
  const icon = join(root, "resources/AppIcon-1024.png");
  if (existsSync(icon)) copyFileSync(icon, join(app, "Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png"));
  const projectPath = join(root, "ios/App/App.xcodeproj/project.pbxproj");
  const project = xcode.project(projectPath);
  project.parseSync();
  if (!project.hasFile("TokenHubSecureStorePlugin.swift")) {
    const group = project.findPBXGroupKey({ path: "App" });
    if (!group) throw new Error("The iOS App source group was not found");
    project.addSourceFile("TokenHubSecureStorePlugin.swift", { target: project.getFirstTarget().uuid }, group);
    writeFileSync(projectPath, project.writeSync());
  }
  // Use the bridge controller that registers the plugin.
  edit(join(app, "Base.lproj/Main.storyboard"), (text) => text.replace(/customClass="CAPBridgeViewController" customModule="Capacitor"/, 'customClass="TokenHubBridgeViewController" customModule="App" customModuleProvider="target"'));
  // tokenhub:// deep links.
  edit(join(app, "Info.plist"), (text) => text.includes("<string>tokenhub</string>") ? text : text.replace(/<dict>\n/, "<dict>\n\t<key>CFBundleURLTypes</key>\n\t<array>\n\t\t<dict>\n\t\t\t<key>CFBundleURLSchemes</key>\n\t\t\t<array>\n\t\t\t\t<string>tokenhub</string>\n\t\t\t</array>\n\t\t</dict>\n\t</array>\n"));
  // Camera permission text: the /app/ page scans sign-in QR codes (getUserMedia).
  edit(join(app, "Info.plist"), (text) => text.includes("NSCameraUsageDescription") ? text : text.replace(/<dict>\n/, "<dict>\n\t<key>NSCameraUsageDescription</key>\n\t<string>扫描后台显示的 TokenHub 认证二维码以登录。</string>\n"));
  // Native push (APNs): hand the device token to @capacitor/push-notifications.
  edit(join(app, "AppDelegate.swift"), (text) => text.includes("capacitorDidRegisterForRemoteNotifications") ? text : text.replace(/\n\}\s*$/, `

    func application(_ application: UIApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
        NotificationCenter.default.post(name: .capacitorDidRegisterForRemoteNotifications, object: deviceToken)
    }

    func application(_ application: UIApplication, didFailToRegisterForRemoteNotificationsWithError error: Error) {
        NotificationCenter.default.post(name: .capacitorDidFailToRegisterForRemoteNotifications, object: error)
    }
}
`));
  console.log("iOS: secure-store plugin registered in the App target; for native push enable Signing & Capabilities → Push Notifications (and Background Modes → Remote notifications), then build.");
} else if (platform === "android") {
  const pkg = join(root, "android/app/src/main/java/com/qianqian/tokenhub");
  copyFileSync(join(root, "plugins/android/TokenHubSecureStorePlugin.java"), join(pkg, "TokenHubSecureStorePlugin.java"));
  copyFileSync(join(root, "plugins/android/MainActivity.java"), join(pkg, "MainActivity.java"));
  edit(join(root, "android/app/build.gradle"), (text) => text.includes("security-crypto") ? text : text.replace(/dependencies \{\n/, 'dependencies {\n    implementation "androidx.security:security-crypto:1.1.0-alpha06"\n'));
  const manifest = join(root, "android/app/src/main/AndroidManifest.xml");
  edit(manifest, (text) => text.replace(/android:allowBackup="true"/, 'android:allowBackup="false"'));
  edit(manifest, (text) => text.includes('android:scheme="tokenhub"') ? text : text.replace(/(<activity[\s\S]*?>)/, `$1\n            <intent-filter>\n                <action android:name="android.intent.action.VIEW" />\n                <category android:name="android.intent.category.DEFAULT" />\n                <category android:name="android.intent.category.BROWSABLE" />\n                <data android:scheme="tokenhub" android:host="link" />\n            </intent-filter>`));
  // Native push (FCM): the generated app/build.gradle applies the google-services
  // plugin only when app/google-services.json exists (from the Firebase console).
  const googleServices = process.env.TOKENHUB_GOOGLE_SERVICES_JSON;
  if (googleServices && existsSync(googleServices)) {
    copyFileSync(googleServices, join(root, "android/app/google-services.json"));
    console.log("Android: google-services.json copied (FCM enabled).");
  } else if (!existsSync(join(root, "android/app/google-services.json"))) {
    console.log("Android: no google-services.json; native push stays off until you add one (set TOKENHUB_GOOGLE_SERVICES_JSON=/path/google-services.json and rerun).");
  }
  edit(manifest, (text) => text.includes("POST_NOTIFICATIONS") ? text : text.replace(/(<manifest[^>]*>)/, `$1\n    <uses-permission android:name="android.permission.POST_NOTIFICATIONS" />`));
  console.log("Android: plugin copied and registered in MainActivity.");
} else {
  console.error("usage: node scripts/install-plugin.mjs ios|android");
  process.exit(1);
}
if (!existsSync(join(root, "www/index.html"))) {
  console.error("www/index.html is missing");
  process.exit(1);
}
