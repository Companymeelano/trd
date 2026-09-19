# MeeLano Trading Intelligence — Android client
#
# The app parses JSON with the platform org.json API and talks to the PHP core
# over OkHttp. Neither uses reflection over app model classes, so the default
# AndroidX/Compose/OkHttp consumer rules are enough. Kept explicit for clarity.

-dontwarn okhttp3.**
-dontwarn okio.**
-dontwarn org.conscrypt.**
-dontwarn org.bouncycastle.**
-dontwarn org.openjsse.**

# Keep line numbers for crash reports but obfuscate the rest.
-keepattributes SourceFile,LineNumberTable
-renamesourcefileattribute SourceFile
