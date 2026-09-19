package ir.meelano.trader;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.app.DownloadManager;
import android.content.Context;
import android.content.Intent;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.GradientDrawable;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.view.Gravity;
import android.view.View;
import android.webkit.DownloadListener;
import android.webkit.SafeBrowsingResponse;
import android.webkit.SslErrorHandler;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

/**
 * میلانو تریدینگ اینتلیجنس — پوشش اندروید (WebView تمام‌صفحه، نسخهٔ ۵).
 *
 * قابلیت‌ها:
 *  - اجرای امن نسخهٔ وب با رمزگذاری کامل (SSL اجباری، بدون محتوای مخلوط)
 *  - دانلود فایل‌ها (خروجی/گزارش‌ها) با DownloadManager سیستم
 *  - لینک‌های خارجی (دامنهٔ دیگر / تلگرام / ایمیل / تلفن) در مرورگر باز می‌شوند
 *  - Safe Browsing گوگل: صفحات خطرناک مسدود می‌شوند
 *  - صفحهٔ خطای فارسی با دکمهٔ «تلاش دوباره» به‌جای صفحهٔ سیاه
 *  - نوار پیشرفت و مدیریت صحیح دکمهٔ بازگشت (تاریخ WebView)
 *
 * بدون هیچ وابستگی خارجی — فقط WebView خودِ اندروید.
 * Meelano Studio Design — Milad Yaghoobi
 */
public class MainActivity extends Activity {

    private WebView webView;
    private ProgressBar progressBar;
    private LinearLayout errorBox;
    private String appHost;

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        appHost = Uri.parse(getString(R.string.app_url)).getHost();

        getWindow().setStatusBarColor(Color.parseColor("#0b1020"));
        getWindow().setNavigationBarColor(Color.parseColor("#0b1020"));
        getWindow().getDecorView().setSystemUiVisibility(
                View.SYSTEM_UI_FLAG_LAYOUT_STABLE | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN);

        webView = new WebView(this);
        progressBar = new ProgressBar(this);
        errorBox = buildErrorBox();

        WebSettings s = webView.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setCacheMode(WebSettings.LOAD_DEFAULT);
        s.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        s.setLoadWithOverviewMode(true);
        s.setUseWideViewPort(true);
        s.setTextZoom(100);
        s.setSupportZoom(false);
        s.setAllowFileAccess(false);          // امنیت: دسترسی به file:// مسدود
        s.setAllowContentAccess(false);
        s.setMediaPlaybackRequiresUserGesture(true);

        webView.setBackgroundColor(Color.parseColor("#0b1020"));
        webView.setWebViewClient(new PanelClient());
        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onProgressChanged(WebView view, int p) {
                super.onProgressChanged(view, p);
                progressBar.setVisibility(p >= 100 ? View.GONE : View.VISIBLE);
            }

            @Override
            public void onSafeBrowsingHit(WebView view, WebResourceRequest request, int threatType, SafeBrowsingResponse callback) {
                // هرگز صفحهٔ خطرناک را بی‌صدا رد نکن؛ گزارش بده و به صفحهٔ امن برگرد
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
                    callback.backToSafety(true);
                } else {
                    callback.proceed(false);
                }
                Toast.makeText(MainActivity.this, R.string.unsafe_blocked, Toast.LENGTH_LONG).show();
            }
        });

        // دانلود فایل‌ها (خروجی CSV/گزارش) با DownloadManager سیستم
        webView.setDownloadListener(new DownloadListener() {
            @Override
            public void onDownloadStart(String url, String userAgent, String contentDisposition, String mimetype, long contentLength) {
                try {
                    DownloadManager.Request req = new DownloadManager.Request(Uri.parse(url));
                    req.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
                    String cookie = android.webkit.CookieManager.getInstance().getCookie(url);
                    if (cookie != null) { req.addRequestHeader("Cookie", cookie); }
                    String name = URLGuess.guess(contentDisposition, url, mimetype);
                    req.setDestinationInExternalPublicDir(
                            android.os.Environment.DIRECTORY_DOWNLOADS, "MeelanoTrader/" + name);
                    DownloadManager dm = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
                    if (dm != null) {
                        dm.enqueue(req);
                        Toast.makeText(MainActivity.this, R.string.download_started, Toast.LENGTH_SHORT).show();
                    }
                } catch (Exception e) {
                    openExternally(url); // فول‌بک: باز کردن در مرورگر
                }
            }
        });

        FrameLayout root = new FrameLayout(this);
        root.setBackgroundColor(Color.parseColor("#0b1020"));
        root.addView(webView, lp());
        root.addView(progressBar, lp());
        root.addView(errorBox); // LayoutParams خودِ errorBox (متمرکز، wrap)
        setContentView(root);

        load();
    }

    private FrameLayout.LayoutParams lp() {
        return new FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT,
                FrameLayout.LayoutParams.MATCH_PARENT);
    }

    private void load() {
        errorBox.setVisibility(View.GONE);
        progressBar.setVisibility(View.VISIBLE);
        webView.loadUrl(getString(R.string.app_url));
    }

    /** باز کردن لینک در مرورگر خارجی. */
    private void openExternally(String url) {
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
        } catch (Exception ignored) { }
    }

    /** جعبهٔ خطای فارسی (به‌جای صفحهٔ سیاه). */
    private LinearLayout buildErrorBox() {
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setGravity(Gravity.CENTER);
        box.setPadding(64, 48, 64, 48);

        GradientDrawable card = new GradientDrawable();
        card.setColor(Color.parseColor("#111a33"));
        card.setCornerRadius(28f);
        card.setStroke(2, Color.parseColor("#f5b731"));
        box.setBackground(card);
        box.setVisibility(View.GONE);

        TextView icon = new TextView(this);
        icon.setText("\u26A0\uFE0F");           // ⚠️
        icon.setTextSize(44);
        icon.setGravity(Gravity.CENTER);
        box.addView(icon);

        TextView title = new TextView(this);
        title.setText(R.string.err_title);
        title.setTextColor(Color.parseColor("#ffd76a"));
        title.setTextSize(20);
        title.setTypeface(null, Typeface.BOLD);
        title.setGravity(Gravity.CENTER);
        box.addView(title);

        TextView body = new TextView(this);
        body.setText(R.string.err_body);
        body.setTextColor(Color.parseColor("#eef2ff"));
        body.setTextSize(15);
        body.setGravity(Gravity.CENTER);
        body.setPadding(0, 24, 0, 32);
        box.addView(body);

        Button retry = new Button(this);
        retry.setText(R.string.err_retry);
        retry.setTextColor(Color.parseColor("#0b1020"));
        retry.setBackgroundColor(Color.parseColor("#f5b731"));
        retry.setOnClickListener(v -> load());
        box.addView(retry);

        FrameLayout.LayoutParams p = new FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT,
                FrameLayout.LayoutParams.WRAP_CONTENT);
        p.gravity = Gravity.CENTER;
        box.setLayoutParams(p);
        return box;
    }

    private void showError() {
        progressBar.setVisibility(View.GONE);
        errorBox.setVisibility(View.VISIBLE);
    }

    /** حدس نام فایل از هدر content-Disposition یا URL. */
    static final class URLGuess {
        static String guess(String contentDisposition, String url, String mime) {
            if (contentDisposition != null) {
                java.util.regex.Matcher m = java.util.regex.Pattern
                        .compile("filename\\s*=\\s*\"?([^\"]+)\"?")
                        .matcher(contentDisposition);
                if (m.find()) { return m.group(1); }
            }
            String path = Uri.parse(url).getLastPathSegment();
            if (path != null && path.contains(".")) { return path; }
            if (mime != null && mime.contains("pdf")) { return "meelano-report.pdf"; }
            if (mime != null && mime.contains("csv")) { return "meelano-signals.csv"; }
            return "meelano-download";
        }
    }

    private class PanelClient extends WebViewClient {
        @Override
        public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
            Uri uri = request.getUrl();
            String scheme = uri.getScheme() == null ? "" : uri.getScheme();
            // اسکیم‌های غیر وب → همیشه بیرون از اپ
            if (scheme.startsWith("mailto") || scheme.startsWith("tel")
                    || scheme.startsWith("intent") || scheme.startsWith("tg")) {
                openExternally(uri.toString());
                return true;
            }
            // دامنهٔ دیگر → مرورگر (امنیت: فیشینگ/ردایرکت خارج از پنل)
            if (!scheme.startsWith("http")) { return true; }
            String host = uri.getHost();
            if (host != null && appHost != null && !host.equalsIgnoreCase(appHost)
                    && !host.endsWith("." + appHost)) {
                openExternally(uri.toString());
                return true;
            }
            return false; // داخل پنل بماند
        }

        @Override
        public void onPageFinished(WebView view, String url) {
            super.onPageFinished(view, url);
            progressBar.setVisibility(View.GONE);
        }

        @Override
        public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
            super.onReceivedError(view, request, error);
            if (request.isForMainFrame()) { showError(); }
        }

        @Override
        public void onReceivedHttpError(WebView view, WebResourceRequest request, WebResourceResponse resp) {
            super.onReceivedHttpError(view, request, resp);
            if (request.isForMainFrame() && resp != null && resp.getStatusCode() >= 400) { showError(); }
        }

        @Override
        public void onReceivedSslError(WebView view, SslErrorHandler handler, SslError error) {
            // هرگز خطای SSL را بی‌صدا رد نکن؛ هشدار بده و متوقف شو
            handler.cancel();
            showError();
        }
    }

    @Override
    public void onBackPressed() {
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
        } else {
            super.onBackPressed();
        }
    }

    @Override
    protected void onDestroy() {
        if (webView != null) {
            webView.destroy();
        }
        super.onDestroy();
    }
}
