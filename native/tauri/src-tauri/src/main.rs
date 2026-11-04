// TokenHub desktop shell (macOS / Windows). The start page (../dist) remembers
// TokenHub servers; the window then shows https://<server>/app/. The page keeps
// its device credential in the OS keychain (macOS Keychain, Windows Credential
// Manager) through the commands below. A page can only read or write the entry
// of its own origin: the key comes from the calling webview's URL, never from
// the page itself.
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use keyring::Entry;

const SERVICE: &str = "com.qianqian.tokenhub.device";

fn caller_key(webview: &tauri::Webview) -> Result<String, String> {
    let url = webview.url().map_err(|e| e.to_string())?;
    if url.scheme() != "https" || !url.path().starts_with("/app") {
        return Err("This page may not use the TokenHub secure store".into());
    }
    let host = url.host_str().ok_or("no host")?;
    let origin = match url.port() {
        Some(port) => format!("https://{host}:{port}"),
        None => format!("https://{host}"),
    };
    Ok(format!("tokenhub.device.{origin}"))
}

fn entry(webview: &tauri::Webview, key: &str) -> Result<Entry, String> {
    let expected = caller_key(webview)?;
    if key != expected {
        return Err("This page may not use the TokenHub secure store".into());
    }
    Entry::new(SERVICE, &expected).map_err(|e| e.to_string())
}

#[tauri::command]
fn secure_get(webview: tauri::Webview, key: String) -> Result<Option<String>, String> {
    match entry(&webview, &key)?.get_password() {
        Ok(value) => Ok(Some(value)),
        Err(keyring::Error::NoEntry) => Ok(None),
        Err(e) => Err(e.to_string()),
    }
}

#[tauri::command]
fn secure_set(webview: tauri::Webview, key: String, value: String) -> Result<(), String> {
    entry(&webview, &key)?.set_password(&value).map_err(|e| e.to_string())
}

#[tauri::command]
fn secure_remove(webview: tauri::Webview, key: String) -> Result<(), String> {
    match entry(&webview, &key)?.delete_credential() {
        Ok(()) | Err(keyring::Error::NoEntry) => Ok(()),
        Err(e) => Err(e.to_string()),
    }
}

fn main() {
    tauri::Builder::default()
        .plugin(tauri_plugin_notification::init())
        .invoke_handler(tauri::generate_handler![secure_get, secure_set, secure_remove])
        .run(tauri::generate_context!())
        .expect("error while running TokenHub");
}
