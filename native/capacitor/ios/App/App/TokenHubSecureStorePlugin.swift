import Capacitor
import Foundation
import Security

/// Keychain storage for the TokenHub device credential (one item per server
/// origin). Items are readable only after the first unlock and never leave this
/// device (no iCloud Keychain sync, not in backups restored to other devices).
@objc(TokenHubSecureStorePlugin)
public class TokenHubSecureStorePlugin: CAPPlugin, CAPBridgedPlugin {
    public let identifier = "TokenHubSecureStorePlugin"
    public let jsName = "TokenHubSecureStore"
    public let pluginMethods: [CAPPluginMethod] = [
        CAPPluginMethod(name: "get", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "set", returnType: CAPPluginReturnPromise),
        CAPPluginMethod(name: "remove", returnType: CAPPluginReturnPromise)
    ]

    private let service = "com.qianqian.tokenhub.device"

    /// Origin of the page that is calling. A page may only touch the credential of its own
    /// origin, so a foreign site opened inside the shell cannot read another server's entry.
    private func callerOrigin() -> String? {
        var url: URL?
        if Thread.isMainThread {
            url = bridge?.webView?.url
        } else {
            DispatchQueue.main.sync { url = self.bridge?.webView?.url }
        }
        guard let url = url, let scheme = url.scheme, let host = url.host else { return nil }
        let port = url.port.map { ":\($0)" } ?? ""
        return "\(scheme)://\(host)\(port)"
    }

    private func authorizedKey(_ call: CAPPluginCall) -> String? {
        guard let key = call.getString("key"), let origin = callerOrigin(), origin.hasPrefix("https://"),
              key == "tokenhub.device.\(origin)" else {
            call.reject("This page may not use the TokenHub secure store")
            return nil
        }
        return key
    }

    private func baseQuery(_ key: String) -> [String: Any] {
        return [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: key
        ]
    }

    @objc func get(_ call: CAPPluginCall) {
        guard let key = authorizedKey(call) else { return }
        var query = baseQuery(key)
        query[kSecReturnData as String] = true
        query[kSecMatchLimit as String] = kSecMatchLimitOne
        var item: CFTypeRef?
        let status = SecItemCopyMatching(query as CFDictionary, &item)
        if status == errSecItemNotFound { return call.resolve(["value": NSNull()]) }
        guard status == errSecSuccess, let data = item as? Data, let value = String(data: data, encoding: .utf8) else {
            return call.reject("Keychain read failed (\(status))")
        }
        call.resolve(["value": value])
    }

    @objc func set(_ call: CAPPluginCall) {
        guard let key = authorizedKey(call) else { return }
        guard let value = call.getString("value") else { return call.reject("value is required") }
        let data = Data(value.utf8)
        // Update in place so a failed write never loses the previous credential.
        let update: [String: Any] = [kSecValueData as String: data]
        var status = SecItemUpdate(baseQuery(key) as CFDictionary, update as CFDictionary)
        if status == errSecItemNotFound {
            var add = baseQuery(key)
            add[kSecValueData as String] = data
            add[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
            status = SecItemAdd(add as CFDictionary, nil)
        }
        status == errSecSuccess ? call.resolve() : call.reject("Keychain write failed (\(status))")
    }

    @objc func remove(_ call: CAPPluginCall) {
        guard let key = authorizedKey(call) else { return }
        let status = SecItemDelete(baseQuery(key) as CFDictionary)
        (status == errSecSuccess || status == errSecItemNotFound) ? call.resolve() : call.reject("Keychain delete failed (\(status))")
    }
}

/// Registers the app-local plugin (Capacitor 6 does not discover plugins
/// that live in the app target). Main.storyboard points at this class.
class TokenHubBridgeViewController: CAPBridgeViewController {
    override open func capacitorDidLoad() {
        bridge?.registerPluginInstance(TokenHubSecureStorePlugin())
    }
}
