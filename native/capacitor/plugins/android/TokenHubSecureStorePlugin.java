package com.qianqian.tokenhub;

import android.content.Context;
import android.content.SharedPreferences;
import android.net.Uri;

import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicReference;

import androidx.security.crypto.EncryptedSharedPreferences;
import androidx.security.crypto.MasterKey;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * Keystore-backed storage for the TokenHub device credential (one entry per
 * server origin). EncryptedSharedPreferences keeps the values encrypted with
 * a key held in the Android Keystore; the file is excluded from backups in
 * the manifest (android:allowBackup="false").
 */
@CapacitorPlugin(name = "TokenHubSecureStore")
public class TokenHubSecureStorePlugin extends Plugin {
    private SharedPreferences store;

    private SharedPreferences store() throws Exception {
        if (store == null) {
            Context context = getContext();
            MasterKey key = new MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build();
            store = EncryptedSharedPreferences.create(
                context,
                "tokenhub_secure_store",
                key,
                EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
            );
        }
        return store;
    }

    /** Origin of the page that is calling; a page may only use the entry of its own origin. */
    private String callerOrigin() {
        AtomicReference<String> url = new AtomicReference<>();
        CountDownLatch done = new CountDownLatch(1);
        getActivity().runOnUiThread(() -> {
            url.set(getBridge().getWebView().getUrl());
            done.countDown();
        });
        try {
            done.await(2, TimeUnit.SECONDS);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            return null;
        }
        if (url.get() == null) {
            return null;
        }
        Uri uri = Uri.parse(url.get());
        if (uri.getScheme() == null || uri.getHost() == null) {
            return null;
        }
        return uri.getScheme() + "://" + uri.getHost() + (uri.getPort() > 0 ? ":" + uri.getPort() : "");
    }

    private String authorizedKey(PluginCall call) {
        String key = call.getString("key");
        String origin = callerOrigin();
        if (key == null || origin == null || !origin.startsWith("https://") || !key.equals("tokenhub.device." + origin)) {
            call.reject("This page may not use the TokenHub secure store");
            return null;
        }
        return key;
    }

    @PluginMethod
    public void get(PluginCall call) {
        String key = authorizedKey(call);
        if (key == null) {
            return;
        }
        try {
            JSObject result = new JSObject();
            String value = store().getString(key, null);
            result.put("value", value == null ? JSObject.NULL : value);
            call.resolve(result);
        } catch (Exception e) {
            call.reject("Secure store read failed", e);
        }
    }

    @PluginMethod
    public void set(PluginCall call) {
        String key = authorizedKey(call);
        if (key == null) {
            return;
        }
        String value = call.getString("value");
        if (value == null) {
            call.reject("value is required");
            return;
        }
        try {
            // commit(): synchronous, so the APP never refreshes with a credential that was not saved.
            if (store().edit().putString(key, value).commit()) {
                call.resolve();
            } else {
                call.reject("Secure store write failed");
            }
        } catch (Exception e) {
            call.reject("Secure store write failed", e);
        }
    }

    @PluginMethod
    public void remove(PluginCall call) {
        String key = authorizedKey(call);
        if (key == null) {
            return;
        }
        try {
            store().edit().remove(key).commit();
            call.resolve();
        } catch (Exception e) {
            call.reject("Secure store delete failed", e);
        }
    }
}
