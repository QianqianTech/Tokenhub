DROP TABLE IF EXISTS waf_events;
DROP TABLE IF EXISTS waf_rule_versions;
DROP TABLE IF EXISTS waf_rules;
DROP TABLE IF EXISTS system_ip_bans;

DELETE FROM system_settings WHERE setting_key IN ('firewall_settings', 'waf_detection_settings');
DELETE FROM log_retention_policies WHERE log_type = 'caddy-security';
