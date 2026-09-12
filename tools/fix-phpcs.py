import io

def patch(path, pairs):
    s = io.open(path, encoding='utf-8').read()
    for old, new in pairs:
        if old not in s:
            print(f"SKIP (not found) in {path}: {old[:60]!r}")
            continue
        s = s.replace(old, new)
    io.open(path, 'w', encoding='utf-8', newline='\n').write(s)

patch('includes/class-zkp-plugin.php', [
    ("public const CRON_HOOK = 'zkp_poll_pending';", "public const CRON_HOOK = 'zerokyc_poll_pending';"),
    ("add_action( 'wp_ajax_zkp_ping', array( 'ZKP_Gateway', 'ajax_ping' ) );",
     "add_action( 'wp_ajax_zerokyc_ping', array( 'ZKP_Gateway', 'ajax_ping' ) );"),
])

patch('includes/class-zkp-cron.php', [
    ("public const HOOK    = 'zkp_poll_pending';", "public const HOOK    = 'zerokyc_poll_pending';"),
    ("public const SLUG    = 'zkp_15min';", "public const SLUG    = 'zerokyc_15min';"),
])

patch('uninstall.php', [
    ("wp_clear_scheduled_hook( 'zkp_poll_pending' );", "wp_clear_scheduled_hook( 'zerokyc_poll_pending' );"),
])

patch('includes/class-zkp-gateway.php', [
    ("body.append( 'action', 'zkp_ping' );", "body.append( 'action', 'zerokyc_ping' );"),
    ("$nonce = wp_create_nonce( 'zkp_ping' );", "$nonce = wp_create_nonce( 'zerokyc_ping' );"),
    ("check_ajax_referer( 'zkp_ping', 'nonce' );", "check_ajax_referer( 'zerokyc_ping', 'nonce' );"),
])

patch('phpcs.xml.dist', [
    ('                <element value="zkp"/>\n', ''),
])

patch('includes/class-zkp-event-store.php', [
    ("$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE event_id = %s', $eventId )",
     "$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_id = %s', self::table(), $eventId )"),
    ("'UPDATE ' . self::table() . ' SET processed_at = %s WHERE event_id = %s AND processed_at IS NULL',",
     "'UPDATE %i SET processed_at = %s WHERE event_id = %s AND processed_at IS NULL',"),
    ("\t\t\t\tcurrent_time( 'mysql' ),\n\t\t\t\t$eventId\n\t\t\t)\n\t\t);\n\t}\n\n\t/**\n\t * EventStoreInterface::markProcessed()",
     "\t\t\t\tself::table(),\n\t\t\t\tcurrent_time( 'mysql' ),\n\t\t\t\t$eventId\n\t\t\t)\n\t\t);\n\t}\n\n\t/**\n\t * EventStoreInterface::markProcessed()"),
    ("'DELETE FROM ' . self::table() . ' WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)',",
     "'DELETE FROM %i WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)',"),
])

patch('includes/class-zkp-invoice-map.php', [
    ("'INSERT INTO ' . self::table() . ' (invoice_id, order_id, created_at)",
     "'INSERT INTO %i (invoice_id, order_id, created_at)"),
    ("ON DUPLICATE KEY UPDATE order_id = VALUES(order_id)',\n\t\t\t\t$invoice_id,",
     "ON DUPLICATE KEY UPDATE order_id = VALUES(order_id)',\n\t\t\t\tself::table(),\n\t\t\t\t$invoice_id,"),
    ("$wpdb->prepare( 'SELECT order_id FROM ' . self::table() . ' WHERE invoice_id = %s LIMIT 1', $invoice_id )",
     "$wpdb->prepare( 'SELECT order_id FROM %i WHERE invoice_id = %s LIMIT 1', self::table(), $invoice_id )"),
    ("$wpdb->prepare( 'SELECT DISTINCT order_id FROM ' . self::table() . ' WHERE created_at >= %s', $mysql_datetime )",
     "$wpdb->prepare( 'SELECT DISTINCT order_id FROM %i WHERE created_at >= %s', self::table(), $mysql_datetime )"),
    ("'DELETE FROM ' . self::table() . ' WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)',",
     "'DELETE FROM %i WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)',"),
])

print('done')
