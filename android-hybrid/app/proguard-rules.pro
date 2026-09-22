# MeeLano Hybrid — Android client
#
# The app talks to the PHP core over OkHttp and exposes one object to JavaScript
# through @JavascriptInterface. WebView resolves those methods by name at
# runtime, so the bridge class and its annotated methods must survive R8.

-keepclassmembers class ir.meelano.hybrid.web.MeelanoBridge {
    @android.webkit.JavascriptInterface <methods>;
}
-keep class ir.meelano.hybrid.web.MeelanoBridge { *; }

-dontwarn okhttp3.**
-dontwarn okio.**
-dontwarn org.conscrypt.**
-dontwarn org.bouncycastle.**
-dontwarn org.openjsse.**

# Keep line numbers for crash reports but obfuscate the rest.
-keepattributes SourceFile,LineNumberTable
-renamesourcefileattribute SourceFile
