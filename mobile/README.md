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
- Camera and location for the site's own upload buttons and location prompts, and a "No internet" page.

**Not in this first version:** location while the rider or partner app is in the background with the screen off.
The site's heartbeat runs while the app is open, as it does in a browser today.

## Secrets: never in git
These stay on the build PC only (`android/.gitignore` blocks them):
- `android/app/google-services.json`: one file covers all three packages. It is the old apps' file, at
  `1.8.40-2\Customer App 1.8.10\android\app\google-services.json`.
- `android/key-customer.properties`, `android/key-rider.properties`, `android/key-partner.properties`. Each live
  app was signed with its **own** key (the old folders hold three different `keystore.jks` files):
  ```
  storeFile=F:/Prod-files/Keystore/<that app's keystore>.jks
  storePassword=...
  keyAlias=upload
  keyPassword=...
  ```

## One-time setup on the build PC
1. Install Android Studio (it includes Java 21) and Node 20 or newer.
2. `git clone` the repo (or `git pull`), then `cd mobile && npm ci`.
3. Copy `google-services.json` and the three `key-*.properties` files into place (see above).
4. Firebase console → project `onecallfix-6b538` → Project settings → each of the three Android apps → add the
   **SHA-1** of that app's upload key, and the **App signing key SHA-1** from Play Console → Setup → App
   signing. Google sign-in fails without them. Get the upload-key SHA-1 with:
   `keytool -list -v -keystore <keystore>.jks -alias upload`

## Every build
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
  book a service and pay with UPI (the UPI app opens and comes back); the call/WhatsApp buttons open the dialer
  or WhatsApp; with the app closed, a booking update arrives as a notification and tapping it opens that page.
- **Rider** (test.rider): opens /rider; allow notifications and location; go online; lock the phone and place a
  test parcel. The offer must ring loudly. Tap it and accept.
- **Partner:** test.provider opens /provider and test.restaurant opens /seller; job offer rings with the app
  closed; upload a document photo with the camera.
- Turn mobile data off and back on: "No internet" page, then "Try again".
