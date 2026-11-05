// After `cap sync`, give each app (Android flavor) its own capacitor.config.json: same site, own start page.
// Flavor assets override src/main/assets, so the customer app uses the main config as it is.
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';

const base = JSON.parse(readFileSync('android/app/src/main/assets/capacitor.config.json', 'utf8'));
const site = new URL(base.server.url).origin;

const apps = {
    rider: { appId: 'com.onecall.deliveryboy', appName: '1CallFix Rider', path: '/rider', ua: '1CallFixApp/rider' },
    partner: { appId: 'com.onecall.vendor', appName: '1CallFix Partner', path: '/app/partner', ua: '1CallFixApp/partner' },
};

base.android = { ...base.android, appendUserAgent: '1CallFixApp/customer' };
writeFileSync('android/app/src/main/assets/capacitor.config.json', JSON.stringify(base, null, 2) + '\n');

for (const [flavor, app] of Object.entries(apps)) {
    const cfg = structuredClone(base);
    cfg.appId = app.appId;
    cfg.appName = app.appName;
    cfg.server.url = site + app.path;
    cfg.android.appendUserAgent = app.ua;
    const dir = `android/app/src/${flavor}/assets`;
    mkdirSync(dir, { recursive: true });
    writeFileSync(`${dir}/capacitor.config.json`, JSON.stringify(cfg, null, 2) + '\n');
    console.log(`${flavor}: ${cfg.server.url}`);
}
