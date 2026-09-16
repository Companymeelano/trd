.class public Lcom/meelano/panel/Bridge;
.super Ljava/lang/Object;
.source "Bridge.java"


# direct methods
.method public constructor <init>()V
    .locals 0

    invoke-direct {p0}, Ljava/lang/Object;-><init>()V

    return-void
.end method


# virtual methods
.method public healthCheck(Ljava/lang/String;)Ljava/lang/String;
    .locals 11
    .annotation runtime Landroid/annotation/JavascriptInterface;
    .end annotation

    # v5 = آدرس جاری · v6 = شمارندهٔ هدایت
    move-object v5, p1
    const/4 v6, 0x0

    :again
    :try_start_0
    new-instance v0, Ljava/net/URL;
    invoke-direct {v0, v5}, Ljava/net/URL;-><init>(Ljava/lang/String;)V

    invoke-virtual {v0}, Ljava/net/URL;->openConnection()Ljava/net/URLConnection;
    move-result-object v1
    check-cast v1, Ljava/net/HttpURLConnection;

    const/16 v2, 0x1b58
    invoke-virtual {v1, v2}, Ljava/net/HttpURLConnection;->setConnectTimeout(I)V

    const v2, 0x2710
    invoke-virtual {v1, v2}, Ljava/net/HttpURLConnection;->setReadTimeout(I)V

    const-string v2, "GET"
    invoke-virtual {v1, v2}, Ljava/net/HttpURLConnection;->setRequestMethod(Ljava/lang/String;)V

    const-string v2, "User-Agent"
    const-string v3, "MeelanoTrader/5.8 Android"
    invoke-virtual {v1, v2, v3}, Ljava/net/URLConnection;->setRequestProperty(Ljava/lang/String;Ljava/lang/String;)V

    const/4 v2, 0x1
    invoke-virtual {v1, v2}, Ljava/net/HttpURLConnection;->setInstanceFollowRedirects(Z)V

    invoke-virtual {v1}, Ljava/net/HttpURLConnection;->getResponseCode()I
    move-result v4

    # هدایت ۳۰۰..۳۰۸؟ (برای http → https که خودکار دنبال نمی‌شود)
    const/16 v2, 0x12c
    if-lt v4, v2, :check200
    const/16 v2, 0x134
    if-gt v4, v2, :check200

    const/4 v2, 0x4
    if-lt v6, v2, :do_redirect
    invoke-virtual {v1}, Ljava/net/HttpURLConnection;->disconnect()V
    const-string v0, "{\"ok\":false,\"error\":\"redirects\"}"
    return-object v0

    :do_redirect
    const-string v2, "Location"
    invoke-virtual {v1, v2}, Ljava/net/URLConnection;->getHeaderField(Ljava/lang/String;)Ljava/lang/String;
    move-result-object v7

    if-eqz v7, :err_loc
    invoke-virtual {v1}, Ljava/net/HttpURLConnection;->disconnect()V
    new-instance v8, Ljava/net/URL;
    invoke-direct {v8, v0, v7}, Ljava/net/URL;-><init>(Ljava/net/URL;Ljava/lang/String;)V
    invoke-virtual {v8}, Ljava/net/URL;->toString()Ljava/lang/String;
    move-result-object v5
    add-int/lit8 v6, v6, 0x1
    goto :again

    :check200
    const/16 v2, 0xc8
    if-ne v4, v2, :err_http

    invoke-virtual {v1}, Ljava/net/HttpURLConnection;->getInputStream()Ljava/io/InputStream;
    move-result-object v9

    new-instance v10, Ljava/util/Scanner;
    invoke-direct {v10, v9}, Ljava/util/Scanner;-><init>(Ljava/io/InputStream;)V
    const-string v2, "\\A"
    invoke-virtual {v10, v2}, Ljava/util/Scanner;->useDelimiter(Ljava/lang/String;)Ljava/util/Scanner;
    move-result-object v10
    invoke-virtual {v10}, Ljava/util/Scanner;->hasNext()Z
    move-result v2
    if-eqz v2, :empty
    invoke-virtual {v10}, Ljava/util/Scanner;->next()Ljava/lang/String;
    move-result-object v3
    invoke-virtual {v1}, Ljava/net/HttpURLConnection;->disconnect()V
    return-object v3

    :empty
    invoke-virtual {v1}, Ljava/net/HttpURLConnection;->disconnect()V
    const-string v0, "{\"ok\":false,\"error\":\"empty\"}"
    return-object v0
    :try_end_0
    .catch Ljava/lang/Exception; {:try_start_0 .. :try_end_0} :catch_0

    :err_http
    invoke-virtual {v1}, Ljava/net/HttpURLConnection;->disconnect()V
    new-instance v0, Ljava/lang/StringBuilder;
    invoke-direct {v0}, Ljava/lang/StringBuilder;-><init>()V
    const-string v2, "{\"ok\":false,\"error\":\"http\",\"http\":"
    invoke-virtual {v0, v2}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;
    move-result-object v0
    invoke-virtual {v0, v4}, Ljava/lang/StringBuilder;->append(I)Ljava/lang/StringBuilder;
    move-result-object v0
    const-string v2, "}"
    invoke-virtual {v0, v2}, Ljava/lang/StringBuilder;->append(Ljava/lang/String;)Ljava/lang/StringBuilder;
    move-result-object v0
    invoke-virtual {v0}, Ljava/lang/StringBuilder;->toString()Ljava/lang/String;
    move-result-object v0
    return-object v0

    :err_loc
    invoke-virtual {v1}, Ljava/net/HttpURLConnection;->disconnect()V
    const-string v0, "{\"ok\":false,\"error\":\"redirect\"}"
    return-object v0

    :catch_0
    move-exception v0
    const-string v1, "{\"ok\":false,\"error\":\"network\"}"
    return-object v1
.end method
