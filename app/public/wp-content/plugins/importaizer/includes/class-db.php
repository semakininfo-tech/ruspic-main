<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Importaizer_DB {
    public $wpdb;
    public $tables = array();

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $p = $wpdb->prefix . 'importaizer_';
        $this->tables = array(
            'suppliers' => $p . 'suppliers',
            'jobs'      => $p . 'jobs',
            'items'     => $p . 'items',
            'logs'      => $p . 'logs',
        );
    }

    public static function activate() {
        $db = new self();
        $db->install();
        update_option( 'importaizer_db_version', IMPORTAIZER_DB_VERSION );
    }

    public static function deactivate() {}

    public function install() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $this->wpdb->get_charset_collate();
        $t = $this->tables;
        $sql = array();
        $sql[] = "CREATE TABLE {$t['suppliers']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            url varchar(500) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            settings longtext NULL,
            last_analyzed_at datetime NULL,
            last_imported_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY url (url(191)),
            KEY status (status)
        ) {$c};";
        $sql[] = "CREATE TABLE {$t['jobs']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            supplier_id bigint(20) unsigned NOT NULL,
            type varchar(30) NOT NULL DEFAULT 'analysis',
            status varchar(20) NOT NULL DEFAULT 'running',
            total int(11) NOT NULL DEFAULT 0,
            processed int(11) NOT NULL DEFAULT 0,
            created_count int(11) NOT NULL DEFAULT 0,
            updated_count int(11) NOT NULL DEFAULT 0,
            skipped_count int(11) NOT NULL DEFAULT 0,
            failed_count int(11) NOT NULL DEFAULT 0,
            error longtext NULL,
            started_at datetime NOT NULL,
            finished_at datetime NULL,
            PRIMARY KEY (id),
            KEY supplier_id (supplier_id),
            KEY status (status)
        ) {$c};";
        $sql[] = "CREATE TABLE {$t['items']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            supplier_id bigint(20) unsigned NOT NULL,
            job_id bigint(20) unsigned NOT NULL,
            source_id varchar(190) NULL,
            source_url varchar(500) NOT NULL,
            source_sku varchar(100) NULL,
            target_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_hash char(64) NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            last_seen_at datetime NOT NULL,
            last_imported_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY supplier_source (supplier_id,source_url(191)),
            KEY source_sku (supplier_id,source_sku),
            KEY target_product_id (target_product_id)
        ) {$c};";
        $sql[] = "CREATE TABLE {$t['logs']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned NOT NULL,
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY job_id (job_id),
            KEY level (level)
        ) {$c};";
        foreach ( $sql as $query ) { dbDelta( $query ); }
    }

    private function now() { return current_time( 'mysql' ); }

    public function save_supplier( $data, $id = 0 ) {
        $name = sanitize_text_field( $data['name'] ?? '' );
        $url  = esc_url_raw( $data['url'] ?? '' );
        if ( ! $name || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return new WP_Error( 'invalid_supplier', 'Укажите название и корректный URL поставщика.' );
        }
        $row = array(
            'name' => $name,
            'url' => untrailingslashit( $url ),
            'status' => 'active',
            'updated_at' => $this->now(),
        );
        if ( $id ) {
            $this->wpdb->update( $this->tables['suppliers'], $row, array( 'id' => (int) $id ) );
            return (int) $id;
        }
        $row['created_at'] = $this->now();
        $this->wpdb->insert( $this->tables['suppliers'], $row );
        return (int) $this->wpdb->insert_id;
    }

    public function get_supplier( $id ) { return $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->tables['suppliers']} WHERE id=%d", $id ) ); }
    public function get_suppliers() { return $this->wpdb->get_results( "SELECT * FROM {$this->tables['suppliers']} ORDER BY id DESC" ); }

    public function create_job( $supplier_id, $type = 'analysis', $total = 0 ) {
        $this->wpdb->insert( $this->tables['jobs'], array(
            'supplier_id' => (int) $supplier_id,
            'type' => sanitize_key( $type ),
            'status' => 'running',
            'total' => (int) $total,
            'started_at' => $this->now(),
        ) );
        return (int) $this->wpdb->insert_id;
    }

    public function update_job( $id, $data ) {
        $allowed = array( 'status','total','processed','created_count','updated_count','skipped_count','failed_count','error','finished_at' );
        $row = array();
        foreach ( $allowed as $key ) if ( array_key_exists( $key, $data ) ) $row[$key] = $data[$key];
        if ( $row ) $this->wpdb->update( $this->tables['jobs'], $row, array( 'id' => (int) $id ) );
    }

    public function get_job( $id ) { return $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->tables['jobs']} WHERE id=%d", $id ) ); }
    public function get_jobs( $limit = 30 ) { return $this->wpdb->get_results( $this->wpdb->prepare( "SELECT j.*,s.name supplier_name FROM {$this->tables['jobs']} j LEFT JOIN {$this->tables['suppliers']} s ON s.id=j.supplier_id ORDER BY j.id DESC LIMIT %d", min( 100, max( 1, (int) $limit ) ) ) ); }

    public function save_item( $supplier_id, $job_id, $item ) {
        $now = $this->now();
        $source_url = esc_url_raw( $item['source_url'] ?? '' );
        $existing = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->tables['items']} WHERE supplier_id=%d AND source_url=%s", $supplier_id, $source_url ) );
        $row = array(
            'supplier_id' => (int) $supplier_id,
            'job_id' => (int) $job_id,
            'source_id' => sanitize_text_field( $item['source_id'] ?? '' ),
            'source_url' => $source_url,
            'source_sku' => sanitize_text_field( $item['sku'] ?? '' ),
            'target_product_id' => (int) ( $item['target_product_id'] ?? 0 ),
            'source_hash' => sanitize_text_field( $item['source_hash'] ?? '' ),
            'status' => 'active',
            'last_seen_at' => $now,
            'last_imported_at' => ! empty( $item['target_product_id'] ) ? $now : null,
        );
        if ( $existing ) {
            $this->wpdb->update( $this->tables['items'], $row, array( 'id' => (int) $existing->id ) );
            return (int) $existing->id;
        }
        $this->wpdb->insert( $this->tables['items'], $row );
        return (int) $this->wpdb->insert_id;
    }

    public function log( $job_id, $level, $message, $context = array() ) {
        $this->wpdb->insert( $this->tables['logs'], array(
            'job_id' => (int) $job_id,
            'level' => sanitize_key( $level ),
            'message' => sanitize_text_field( $message ),
            'context' => $context ? wp_json_encode( $context, JSON_UNESCAPED_UNICODE ) : null,
            'created_at' => $this->now(),
        ) );
    }

    public function get_logs( $job_id, $limit = 100 ) { return $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->tables['logs']} WHERE job_id=%d ORDER BY id ASC LIMIT %d", $job_id, min( 500, max( 1, (int) $limit ) ) ) ); }
}
