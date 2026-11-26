package id.co.suryakamangta.absensi;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.Activity;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.location.Location;
import android.location.LocationListener;
import android.location.LocationManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.webkit.GeolocationPermissions;
import android.webkit.JavascriptInterface;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

import org.json.JSONObject;

/**
 * Absensi Surya Kamangta — WebView yang membuka web app absensi (assets/index.html)
 * dengan jembatan GPS native (window.SKNative) dan pemilih lampiran.
 */
public class MainActivity extends Activity {

    /** Web absensi di hosting perusahaan; kalau gagal dimuat, pakai salinan di dalam APK. */
    private static final String START_URL = "https://suryakamangta.co.id/absensi-sk/";
    private static final String FALLBACK_URL = "file:///android_asset/index.html";
    private static final int REQ_LOC = 11;
    private static final int REQ_FILE = 12;
    private static final long LOC_TIMEOUT_MS = 15000;
    private static final float GOOD_ACCURACY_M = 20f;

    private WebView web;
    private ValueCallback<Uri[]> fileCb;
    private String pendingLocCb;
    private final Handler main = new Handler(Looper.getMainLooper());

    @SuppressLint({"SetJavaScriptEnabled", "AddJavascriptInterface"})
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        if (Build.VERSION.SDK_INT >= 21) {
            getWindow().setStatusBarColor(Color.parseColor("#0f2a4a"));
        }
        web = new WebView(this);
        setContentView(web);

        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setGeolocationEnabled(true);
        s.setAllowFileAccess(true);
        s.setCacheMode(WebSettings.LOAD_DEFAULT);
        s.setUserAgentString(s.getUserAgentString() + " SKAbsensiApp/" + BuildConfig.VERSION_NAME);

        web.addJavascriptInterface(new Bridge(), "SKNative");

        web.setWebViewClient(new WebViewClient() {
            @Override
            public void onReceivedError(WebView view, WebResourceRequest req, WebResourceError err) {
                if (Build.VERSION.SDK_INT >= 21 && req.isForMainFrame() && !FALLBACK_URL.equals(view.getUrl())) {
                    view.loadUrl(FALLBACK_URL);
                }
            }

            @SuppressWarnings("deprecation")
            @Override
            public void onReceivedError(WebView view, int code, String desc, String failingUrl) {
                if (failingUrl != null && failingUrl.startsWith("https://suryakamangta.co.id")) view.loadUrl(FALLBACK_URL);
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest req) {
                return openExternal(req.getUrl());
            }

            @SuppressWarnings("deprecation")
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, String url) {
                return openExternal(Uri.parse(url));
            }
        });

        web.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onGeolocationPermissionsShowPrompt(String origin, GeolocationPermissions.Callback callback) {
                callback.invoke(origin, true, false);
            }

            @Override
            public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> cb, FileChooserParams params) {
                if (fileCb != null) fileCb.onReceiveValue(null);
                fileCb = cb;
                Intent i = new Intent(Intent.ACTION_GET_CONTENT);
                i.addCategory(Intent.CATEGORY_OPENABLE);
                i.setType("*/*");
                i.putExtra(Intent.EXTRA_MIME_TYPES, new String[]{"image/*", "application/pdf"});
                try {
                    startActivityForResult(Intent.createChooser(i, "Pilih lampiran"), REQ_FILE);
                } catch (Exception e) {
                    fileCb = null;
                    return false;
                }
                return true;
            }
        });

        if (savedInstanceState != null) web.restoreState(savedInstanceState);
        else web.loadUrl(START_URL);

        if (!hasLocationPermission()) requestLocationPermission();
    }

    private boolean openExternal(Uri uri) {
        String scheme = uri.getScheme();
        if ("file".equals(scheme)) return false;
        String url = uri.toString();
        if (url.startsWith("https://suryakamangta.co.id/absensi-sk")) return false;
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, uri));
        } catch (Exception ignored) { }
        return true;
    }

    @Override
    protected void onSaveInstanceState(Bundle out) {
        super.onSaveInstanceState(out);
        web.saveState(out);
    }

    @Override
    public void onBackPressed() {
        if (web != null && web.canGoBack()) web.goBack();
        else super.onBackPressed();
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode == REQ_FILE && fileCb != null) {
            Uri[] result = null;
            if (resultCode == RESULT_OK && data != null && data.getData() != null) {
                result = new Uri[]{data.getData()};
            }
            fileCb.onReceiveValue(result);
            fileCb = null;
        }
    }

    // ---------- Lokasi ----------

    private boolean hasLocationPermission() {
        if (Build.VERSION.SDK_INT < 23) return true;
        return checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
                || checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED;
    }

    private void requestLocationPermission() {
        if (Build.VERSION.SDK_INT >= 23) {
            requestPermissions(new String[]{
                    Manifest.permission.ACCESS_FINE_LOCATION,
                    Manifest.permission.ACCESS_COARSE_LOCATION}, REQ_LOC);
        }
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == REQ_LOC && pendingLocCb != null) {
            String cb = pendingLocCb;
            pendingLocCb = null;
            if (hasLocationPermission()) startLocation(cb);
            else fail(cb, "Izin lokasi ditolak. Buka Pengaturan > Aplikasi > Absensi SK > Izin > Lokasi > Izinkan.");
        }
    }

    private void fail(String cb, String msg) {
        try {
            JSONObject o = new JSONObject();
            o.put("error", msg);
            send(cb, o);
        } catch (Exception ignored) { }
    }

    private void send(final String cb, final JSONObject o) {
        final String safeCb = cb.replaceAll("[^A-Za-z0-9_$]", "");
        main.post(new Runnable() {
            @Override
            public void run() {
                String js = "window['" + safeCb + "'] && window['" + safeCb + "'](" + JSONObject.quote(o.toString()) + ")";
                web.evaluateJavascript(js, null);
            }
        });
    }

    @SuppressLint("MissingPermission")
    private void startLocation(final String cb) {
        final LocationManager lm = (LocationManager) getSystemService(LOCATION_SERVICE);
        if (lm == null) { fail(cb, "Layanan lokasi tidak tersedia."); return; }
        boolean gps = lm.isProviderEnabled(LocationManager.GPS_PROVIDER);
        boolean net = lm.isProviderEnabled(LocationManager.NETWORK_PROVIDER);
        if (!gps && !net) {
            fail(cb, "Lokasi (GPS) HP mati. Nyalakan Lokasi di HP lalu coba lagi.");
            return;
        }
        final Location[] best = new Location[1];
        final boolean[] done = new boolean[1];
        final LocationListener[] listeners = new LocationListener[2];

        final Runnable finish = new Runnable() {
            @Override
            public void run() {
                if (done[0]) return;
                done[0] = true;
                for (LocationListener l : listeners) if (l != null) lm.removeUpdates(l);
                Location b = best[0];
                if (b == null) {
                    fail(cb, "Lokasi tidak terdeteksi. Pastikan GPS menyala, coba di dekat jendela/luar ruangan lalu ulangi.");
                    return;
                }
                try {
                    JSONObject o = new JSONObject();
                    o.put("lat", b.getLatitude());
                    o.put("lng", b.getLongitude());
                    o.put("acc", b.hasAccuracy() ? b.getAccuracy() : 999);
                    boolean mock;
                    if (Build.VERSION.SDK_INT >= 31) mock = b.isMock();
                    else if (Build.VERSION.SDK_INT >= 18) mock = b.isFromMockProvider();
                    else mock = false;
                    o.put("mock", mock);
                    o.put("provider", b.getProvider());
                    send(cb, o);
                } catch (Exception e) {
                    fail(cb, "Gagal membaca lokasi.");
                }
            }
        };

        LocationListener listener = new LocationListener() {
            @Override
            public void onLocationChanged(Location loc) {
                if (done[0] || loc == null) return;
                if (best[0] == null || (loc.hasAccuracy() && loc.getAccuracy() < best[0].getAccuracy())) best[0] = loc;
                if (LocationManager.GPS_PROVIDER.equals(loc.getProvider()) && loc.hasAccuracy() && loc.getAccuracy() <= GOOD_ACCURACY_M) {
                    main.post(finish);
                }
            }
            @Override public void onStatusChanged(String p, int s, Bundle b) { }
            @Override public void onProviderEnabled(String p) { }
            @Override public void onProviderDisabled(String p) { }
        };

        try {
            if (gps) { lm.requestLocationUpdates(LocationManager.GPS_PROVIDER, 500, 0, listener, Looper.getMainLooper()); listeners[0] = listener; }
            if (net) { lm.requestLocationUpdates(LocationManager.NETWORK_PROVIDER, 500, 0, listener, Looper.getMainLooper()); listeners[1] = listener; }
        } catch (Exception e) {
            fail(cb, "Gagal memulai GPS: " + e.getMessage());
            return;
        }
        main.postDelayed(finish, LOC_TIMEOUT_MS);
    }

    /** Dipanggil dari JavaScript: SKNative.getLocation('namaCallback') */
    public class Bridge {
        @JavascriptInterface
        public void getLocation(final String cb) {
            main.post(new Runnable() {
                @Override
                public void run() {
                    if (!hasLocationPermission()) {
                        pendingLocCb = cb;
                        requestLocationPermission();
                    } else {
                        startLocation(cb);
                    }
                }
            });
        }

        @JavascriptInterface
        public String version() {
            return BuildConfig.VERSION_NAME;
        }
    }
}
