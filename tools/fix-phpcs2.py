import io, re

def patch(path, pairs):
    s = io.open(path, encoding='utf-8').read()
    for old, new in pairs:
        if old not in s:
            print(f"SKIP {path}: {old[:50]!r}")
            continue
        s = s.replace(old, new)
    io.open(path, 'w', encoding='utf-8', newline='\n').write(s)

patch('includes/class-zkp-event-store.php', [
    ("\t\t$exists = $wpdb->query( 'SELECT 1 FROM ' . self::table() . ' LIMIT 1' );",
     "\t\t$exists = $wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', self::table() ) );"),
    ("\tpublic function has( string $eventId ): bool {",
     "\tpublic function has( string $event_id ): bool {"),
    ("\t\t\t$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_id = %s', self::table(), $eventId )",
     "\t\t\t$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_id = %s', self::table(), $event_id )"),
    ("\tpublic function markProcessed( string $eventId ): void {",
     "\tpublic function markProcessed( string $event_id ): void {"),
    ("\t\t\t\t'UPDATE %i SET processed_at = %s WHERE event_id = %s AND processed_at IS NULL',\n\t\t\t\tcurrent_time( 'mysql' ),\n\t\t\t\t$eventId",
     "\t\t\t\t'UPDATE %i SET processed_at = %s WHERE event_id = %s AND processed_at IS NULL',\n\t\t\t\tself::table(),\n\t\t\t\tcurrent_time( 'mysql' ),\n\t\t\t\t$event_id"),
])

patch('includes/class-zkp-invoice-map.php', [
    ("\t\t$exists = $wpdb->query( 'SELECT 1 FROM ' . self::table() . ' LIMIT 1' );",
     "\t\t$exists = $wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', self::table() ) );"),
])

# phpcs:ignore for SDK camelCase public properties (cross-library API, not ours to rename)
IGNORE = ' // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- SDK DTO property'
props = ('->invoiceId', '->checkoutUrl', '->expiresAt', '->paidAsset', '->paidAmount')

for path in ('includes/class-zkp-gateway.php', 'includes/class-zkp-order-service.php', 'includes/class-zkp-webhook-controller.php'):
    lines = io.open(path, encoding='utf-8').read().split('\n')
    out = []
    for line in lines:
        if 'phpcs:ignore' in line or line.strip().startswith('*'):
            out.append(line)
            continue
        if any(p in line for p in props):
            line = line.rstrip() + IGNORE
        out.append(line)
    io.open(path, 'w', encoding='utf-8', newline='\n').write('\n'.join(out))

print('done')
