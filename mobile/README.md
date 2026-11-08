# 1CallFix Android apps

There are three Play Store apps. All three are thin shells over the live site, so nothing is rebuilt and every screen
on www.1callfix.com is already in them. They replace the old Glover Flutter apps under **the same package names**,
so each build is an update to the app people already have.

| App (flavor) | Package (Play Store) | Opens | Old versionCode |
|---|---|---|---|
| `customer` | `com.call.customer` | `/` | 92 |
| `rider` | `com.onecall.deliveryboy` | `/rider` | 86 |
| `partner` | `com.onecall.vendor` | `/app/partner`, which sends service partners to /provider and shops/restaurants to /seller | 82 |

What the app adds on top of the site:
- **Push notifications, even when the app is closed.** The site sends the phone's token to `/push/token`
  (`resources/js/native-app.js`) and `PushService.java` shows the server's pushes. Job and delivery offers
  (`kind=offer`) ring loudly on the "New job and delivery offers" channel.
- **Google sign-in.** Google blocks its sign-in page inside apps, so the app signs in natively and Firebase
  continues exactly as on the website (`resources/js/customer-auth.js`).
- **Phone, UPI, WhatsApp and Maps links** open in their own apps (`MainActivity.java`).
- **Voice search (mic in the search box).** The app listens with the phone's own Google speech
  (`@capacitor-community/speech-recognition`, `resources/js/search-bar.js`). The first tap asks "Allow microphone?";
  Google's "Speak now" box opens and the words go into the search box. Apps built before 2026-11-08 show no mic.
- Camera and location for the site's own upload buttons and location prompts, and a "No internet" page.

**Not in this first version:** location while the rider or partner app is in the background with the screen off.
The site's heartbeat runs while the app is open, as it does in a browser today.

## Secrets: never in git
These stay on the build PC only (`android/.gitignore` blocks them):
- `android/app/google-services.json`: one file covers all three packages. It is the old apps' file, at
  `F:\Prod-files\Glover\1.8.10\1.8.10 - 2\Apps\Customer\1.8.12\android\app\google-services.json`.
- `android/key.properties`: **one** file for all three apps. Customer, rider and partner are all signed with
  the same upload key, `F:\Prod-files\Keystore\keystore.jks` (alias `upload`, upload-key SHA-1
  `2D:66:61:59:41:4C:59:C1:BD:65:40:86:51:AC:B6:BA:B9:4F:54:A0`, the same as `upload_certificate.pem` beside
  it). The password is in the old apps' `android\key.properties` under that `Apps` folder:
  ```
  storeFile=F:/Prod-files/Keystore/keystore.jks
  storePassword=...
  keyAlias=upload
  keyPassword=...
  ```
  Without this file the release build is unsigned and Play Console rejects it.
  `F:\Prod-files\New Keystore` holds a different key (SHA-1 `92:FB:9E:…`) that none of the live apps use.

## One-time setup on the build PC
1. Install Android Studio and Node 20 or newer. Install **JDK 21** too: Android Studio now bundles JDK 25,
   and Gradle 8.14 fails on it ("Unsupported class file major version 69"). Unpack the Temurin 21 zip to
   `%USERPROFILE%\.jdks\` (no admin needed) and set `JAVA_HOME` to it before building (see below).
2. `git clone` the repo (or `git pull`), then `cd mobile && npm ci`.
3. Copy `google-services.json` and `key.properties` into place (see above).
4. Firebase console → project `onecallfix-6b538` → Project settings → each of the three Android apps → add the
   upload-key **SHA-1** above (the same for all three), and the **App signing key SHA-1** from Play Console →
   Setup → App signing for each app. Google sign-in fails without them. Check the upload-key SHA-1 with:
   `keytool -list -v -keystore F:\Prod-files\Keystore\keystore.jks -alias upload`

## Every build
In PowerShell, first point Gradle at JDK 21 (only for that window):
`$env:JAVA_HOME="$env:USERPROFILE\.jdks\jdk-21.0.13+9"` (use the folder name you unpacked).
```
cd mobile
git pull
npm ci
npm run sync
cd android
gradlew bundleCustomerRelease -PcustomerVersionCode=<live versionCode + 1>
gradlew bundleRiderRelease    -PriderVersionCode=<live versionCode + 1>
gradlew bundlePartnerRelease  -PpartnerVersionCode=<live versionCode + 1>
```
The files to upload are `android/app/build/outputs/bundle/<app>Release/app-<app>-release.aab`.
Without `-P…VersionCode` the build uses 93 / 87 / 83. Play Console → the app → Releases shows the live number,
and the new one must be higher.

For a quick test on a phone plugged in by USB: `gradlew installCustomerDebug` (or Rider / Partner).

Upload each `.aab` to Play Console → **Testing → Internal testing** first. Install from the tester link and do
the walk-through below, then promote the release to Production.

## Walk-through on a real phone
- **Customer:** home page opens; sign in with mobile + password, with OTP, and with Google; allow notifications;
  tap the mic in the search box, allow the microphone, say "AC repair" (the words appear and suggestions open); book a service and pay with UPI (the UPI app opens and comes back); the call/WhatsApp buttons open the dialer
  or WhatsApp; with the app closed, a booking update arrives as a notification and tapping it opens that page.
- **Rider** (test.rider): opens /rider; allow notifications and location; go online; lock the phone and place a
  test parcel. The offer must ring loudly. Tap it and accept.
- **Partner:** test.provider opens /provider and test.restaurant opens /seller; job offer rings with the app
  closed; upload a document photo with the camera.
- Turn mobile data off and back on: "No internet" page, then "Try again".
