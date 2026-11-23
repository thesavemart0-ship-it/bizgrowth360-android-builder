package in.bizgrowth360.demoapp;

import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.view.WindowManager;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.widget.Toast;
import androidx.core.content.ContextCompat;
import com.getcapacitor.BridgeActivity;
import com.getcapacitor.BridgeWebViewClient;
import com.getcapacitor.WebViewListener;

public class MainActivity extends BridgeActivity {

    private long backPressedTime = 0;
    private Toast backToast;
    private boolean allowExternalLinks = true;
    private String websiteHost = "";
    private boolean offlinePageShown = false;

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        allowExternalLinks = getResources().getBoolean(R.bool.allow_external_links);
        websiteHost = getString(R.string.custom_website_host);
        if (!getResources().getBoolean(R.bool.enable_status_bar)) {
            getWindow().setFlags(
                WindowManager.LayoutParams.FLAG_FULLSCREEN,
                WindowManager.LayoutParams.FLAG_FULLSCREEN
            );
        }

        if (getBridge() == null) {
            return;
        }

        WebView webView = getBridge().getWebView();
        if (webView != null) {
            webView.setBackgroundColor(ContextCompat.getColor(this, R.color.backgroundColor));
            WebSettings settings = webView.getSettings();
            settings.setDomStorageEnabled(true);
            settings.setDatabaseEnabled(true);
            settings.setJavaScriptEnabled(true);
            settings.setAllowFileAccess(true);
            settings.setAllowContentAccess(false);
            settings.setCacheMode(WebSettings.LOAD_DEFAULT);
            settings.setSupportZoom(false);
            settings.setBuiltInZoomControls(false);
            settings.setDisplayZoomControls(false);
        }

        // Keep the configured website (and its subdomains) inside the app.
        // Other hosts are either opened in the system browser or blocked,
        // depending on allow_external_links.
        getBridge().setWebViewClient(new BridgeWebViewClient(getBridge()) {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri url = request.getUrl();
                if (isSpecialScheme(url)) {
                    return false;
                }
                if (shouldStayInApp(url)) {
                    return false;
                }
                return handleExternalNavigation(url);
            }

            @SuppressWarnings("deprecation")
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, String url) {
                Uri uri = Uri.parse(url);
                if (isSpecialScheme(uri)) {
                    return false;
                }
                if (shouldStayInApp(uri)) {
                    return false;
                }
                return handleExternalNavigation(uri);
            }
        });

        // Offline / failed main-frame fallback to the bundled branded error page.
        getBridge().addWebViewListener(new WebViewListener() {
            @Override
            public void onReceivedError(WebView view) {
                if (view == null || offlinePageShown) {
                    return;
                }
                offlinePageShown = true;
                String appUrl = getBridge().getAppUrl();
                String errorUrl = (appUrl == null ? "https://localhost" : appUrl)
                    + "/index.html?state=offline";
                view.post(() -> view.loadUrl(errorUrl));
            }
        });
    }

    private boolean isSpecialScheme(Uri url) {
        if (url == null || url.getScheme() == null) {
            return true;
        }
        String scheme = url.getScheme().toLowerCase();
        return "about".equals(scheme) || "data".equals(scheme) || "blob".equals(scheme);
    }

    private boolean shouldStayInApp(Uri url) {
        if (url == null || url.getScheme() == null) {
            return false;
        }
        String scheme = url.getScheme().toLowerCase();
        if ("http".equals(scheme) || "https".equals(scheme)) {
            String host = url.getHost() == null ? "" : url.getHost().toLowerCase();
            if (isAppHost(host)) {
                offlinePageShown = false;
                return true;
            }
        }
        return false;
    }

    private boolean handleExternalNavigation(Uri url) {
        if (url == null) {
            return true;
        }
        String scheme = url.getScheme() == null ? "" : url.getScheme().toLowerCase();

        if ("javascript".equals(scheme) || "file".equals(scheme)) {
            return true;
        }

        if (!allowExternalLinks) {
            Toast.makeText(this, "External links are disabled in this app", Toast.LENGTH_SHORT).show();
            return true;
        }

        if ("http".equals(scheme) || "https".equals(scheme)) {
            return getBridge().launchIntent(url);
        }

        // tel:, mailto:, sms:, whatsapp:, market:, upi:, intent:, etc.
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, url));
        } catch (ActivityNotFoundException ignored) {
            Toast.makeText(this, "No app can handle this link", Toast.LENGTH_SHORT).show();
        }
        return true;
    }

    private boolean isAppHost(String host) {
        if (host == null || host.isEmpty()) {
            return false;
        }
        String configured = websiteHost == null ? "" : websiteHost.toLowerCase();
        return host.equals(configured) || host.equals("localhost") || host.equals("127.0.0.1")
            || (!configured.isEmpty() && host.endsWith("." + configured));
    }

    /**
     * Handles Android hardware back button:
     * - Navigates backward within WebView history if pages exist.
     * - Prevents accidental app exit at root page with a 2-second confirmation toast.
     */
    @Override
    public void onBackPressed() {
        WebView webView = (getBridge() != null) ? getBridge().getWebView() : null;
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
        } else {
            if (backPressedTime + 2000 > System.currentTimeMillis()) {
                if (backToast != null) {
                    backToast.cancel();
                }
                super.onBackPressed();
            } else {
                backToast = Toast.makeText(this, "Press back again to exit", Toast.LENGTH_SHORT);
                backToast.show();
                backPressedTime = System.currentTimeMillis();
            }
        }
    }
}
