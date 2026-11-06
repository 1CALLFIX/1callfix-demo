package com.onecallfix.shell;

import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.webkit.WebResourceRequest;
import android.webkit.WebView;

import com.getcapacitor.BridgeActivity;
import com.getcapacitor.BridgeWebViewClient;

import java.net.URISyntaxException;

/**
 * The whole app is the live 1CallFix site in a web view (see mobile/README.md). This activity only adds what a
 * web view cannot do by itself: open phone/UPI/WhatsApp/maps links in their own apps, and open the page a push
 * notification points to.
 */
public class MainActivity extends BridgeActivity {

    /** True while the app is on screen: the site rings for offers itself then, so PushService stays quiet. */
    static volatile boolean inForeground = false;

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        PushChannels.create(this);

        bridge.setWebViewClient(new BridgeWebViewClient(bridge) {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                if (openOutside(request.getUrl())) {
                    return true;
                }
                return super.shouldOverrideUrlLoading(view, request);
            }
        });

        openLinkFrom(getIntent());
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        openLinkFrom(intent);
    }

    @Override
    public void onResume() {
        super.onResume();
        inForeground = true;
    }

    @Override
    public void onPause() {
        inForeground = false;
        super.onPause();
    }

    /** A tapped push notification carries the page to open (the same link the web notification opens). */
    private void openLinkFrom(Intent intent) {
        if (intent == null || bridge == null) {
            return;
        }
        String link = intent.getStringExtra(PushService.EXTRA_LINK);
        if (link == null || link.isEmpty()) {
            return;
        }
        intent.removeExtra(PushService.EXTRA_LINK);

        String server = bridge.getServerUrl().replaceAll("/+$", "");
        String url = link.startsWith("http") ? link : server + (link.startsWith("/") ? link : "/" + link);
        String targetHost = Uri.parse(url).getHost();
        String serverHost = stripWww(Uri.parse(server).getHost());
        // Only ever open our own pages from a notification.
        if (targetHost != null && stripWww(targetHost).equals(serverHost)) {
            bridge.getWebView().post(() -> bridge.getWebView().loadUrl(url));
        }
    }

    private static String stripWww(String host) {
        return host == null ? "" : host.toLowerCase().replaceFirst("^www\\.", "");
    }

    /**
     * Links the web view must hand to another app: tel:, mailto:, upi:, whatsapp:, intent:// (Razorpay UPI apps),
     * and WhatsApp / Google Maps / Play Store web links. Everything else stays in the app.
     */
    private boolean openOutside(Uri uri) {
        String scheme = uri.getScheme() == null ? "" : uri.getScheme().toLowerCase();
        String host = uri.getHost() == null ? "" : uri.getHost().toLowerCase();
        String path = uri.getPath() == null ? "" : uri.getPath();

        try {
            if (scheme.equals("intent")) {
                Intent intent = Intent.parseUri(uri.toString(), Intent.URI_INTENT_SCHEME);
                intent.addCategory(Intent.CATEGORY_BROWSABLE);
                intent.setComponent(null);
                intent.setSelector(null);
                try {
                    startActivity(intent);
                } catch (ActivityNotFoundException e) {
                    String fallback = intent.getStringExtra("browser_fallback_url");
                    if (fallback != null && (fallback.startsWith("https://") || fallback.startsWith("http://"))) {
                        bridge.getWebView().loadUrl(fallback);
                    }
                }
                return true;
            }

            boolean webScheme = scheme.equals("http") || scheme.equals("https") || scheme.equals("about")
                    || scheme.equals("data") || scheme.equals("blob") || scheme.equals("javascript");
            boolean outsideHost = host.equals("wa.me") || host.endsWith("whatsapp.com")
                    || host.equals("maps.google.com") || host.equals("maps.app.goo.gl")
                    || (host.endsWith("google.com") && path.startsWith("/maps"))
                    || host.equals("play.google.com");

            // "Deliver too" / "Offer services too" (owner rule 2026-11-06): the site links to the other 1CallFix
            // app's Play Store page. If that app is already on the phone, open it straight away instead.
            if (host.equals("play.google.com")) {
                String pkg = uri.getQueryParameter("id");
                if (pkg != null && (pkg.equals("com.call.customer") || pkg.equals("com.onecall.deliveryboy") || pkg.equals("com.onecall.vendor"))) {
                    Intent launch = getPackageManager().getLaunchIntentForPackage(pkg);
                    if (launch != null) {
                        startActivity(launch);
                        return true;
                    }
                }
            }

            if (!webScheme || outsideHost) {
                startActivity(new Intent(Intent.ACTION_VIEW, uri).addCategory(Intent.CATEGORY_BROWSABLE));
                return true;
            }
        } catch (URISyntaxException | ActivityNotFoundException e) {
            return true; // nothing on the phone can open it; stay on the current page
        }
        return false;
    }
}
