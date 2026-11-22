package in.bizgrowth360.demoapp;

import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.widget.Toast;
import com.getcapacitor.BridgeActivity;
import com.getcapacitor.WebViewListener;

public class MainActivity extends BridgeActivity {

    private long backPressedTime = 0;
    private Toast backToast;

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        if (getBridge() != null) {
            WebView webView = getBridge().getWebView();
            if (webView != null) {
                WebSettings settings = webView.getSettings();
                settings.setDomStorageEnabled(true);
                settings.setDatabaseEnabled(true);
                settings.setJavaScriptEnabled(true);
                settings.setAllowFileAccess(true);
                settings.setCacheMode(WebSettings.LOAD_DEFAULT);
                settings.setSupportZoom(false);
                settings.setBuiltInZoomControls(false);
            }

            // Hook into webview lifecycle for offline error fallback
            getBridge().addWebViewListener(new WebViewListener() {
                @Override
                public void onReceivedError(WebView webView) {
                    if (webView != null) {
                        webView.post(() -> {
                            webView.loadUrl("file:///android_asset/public/index.html?state=offline");
                        });
                    }
                }
            });
        }
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
