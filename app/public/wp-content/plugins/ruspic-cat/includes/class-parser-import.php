<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class RUSPIC_Cat_Parser_Import {
    private $db;

    public function __construct( $db ) { $this->db = $db; }

    public function import( $file, $options = array() ) {
        if ( ! $file || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new WP_Error( 'invalid_file', 'Файл импорта не получен.' );
        }
        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( 'csv' !== $ext ) {
            return new WP_Error( 'unsupported_format', 'Поддерживается CSV-файл из парсинга. XLSX в этом импортере намеренно не используется.' );
        }
        $fp = fopen( $file['tmp_name'], 'rb' );
        if ( ! $fp ) return new WP_Error( 'file_open_failed', 'Не удалось открыть файл.' );
        $header = fgetcsv( $fp, 0, ';', '"' );
        if ( ! is_array( $header ) ) { fclose( $fp ); return new WP_Error( 'empty_file', 'CSV-файл пуст.' ); }
        $header = array_map( array( $this, 'clean_header' ), $header );
        $required = array( 'sku', 'name', 'description', 'images', 'regular_price', 'technical_specifications', 'product_url' );
        foreach ( $required as $key ) if ( ! in_array( $key, $header, true ) ) { fclose( $fp ); return new WP_Error( 'missing_column', 'В CSV отсутствует обязательная колонка: ' . $key ); }

        $idx = array_flip( $header );
        $processed = $created = $updated = $failed = 0;
        $batch = (int) ( $options['batch_size'] ?? 50 );
        $max = (int) ( $options['max_rows'] ?? 0 );
        $category_fallback = sanitize_text_field( $options['category'] ?? '' );
        while ( ( $row = fgetcsv( $fp, 0, ';', '"' ) ) !== false ) {
            if ( count( $row ) < count( $header ) ) continue;
            if ( $max > 0 && $processed >= $max ) break;
            $processed++;
            $data = $this->row_to_product( $row, $idx, $category_fallback );
            $result = $this->save_product( $data, $options );
            if ( is_wp_error( $result ) ) $failed++;
            elseif ( ! empty( $result['created'] ) ) $created++;
            else $updated++;
            if ( 0 === $processed % $batch ) { @set_time_limit( 10 ); }
        }
        fclose( $fp );
        return compact( 'processed', 'created', 'updated', 'failed' );
    }

    private function row_to_product( $row, $idx, $category_fallback ) {
        $get = function( $name ) use ( $row, $idx ) { return isset( $idx[ $name ], $row[ $idx[ $name ] ] ) ? trim( (string) $row[ $idx[ $name ] ] ) : ''; };
        $sku = $get( 'sku' );
        $name = $get( 'name' );
        $images = array_filter( array_map( 'trim', preg_split( '/\r?\n|\s*[,|]\s*/', $get( 'images' ) ) ) );
        $attrs = $this->parse_specifications( $get( 'technical_specifications' ) );
        $category = $category_fallback;
        if ( ! $category ) {
            $category = $this->infer_category_from_url( $get( 'product_url' ) );
        }
        return array(
            'sku' => $sku,
            'name' => $name,
            'short_description' => $get( 'short_description' ),
            'description' => $get( 'description' ),
            'price' => $this->price( $get( 'regular_price' ) ),
            'currency' => 'RUB',
            'images' => $images,
            'source_url' => esc_url_raw( $get( 'product_url' ) ),
            'attributes' => $attrs,
            'category_path' => $category ? array( $category ) : array(),
            'ean13' => preg_replace( '/\D+/', '', $get( 'ean13' ) ),
        );
    }

    private function save_product( $product, $options ) {
        $existing = $this->find_product( $product['sku'] );
        $category_id = $this->ensure_category_path( $product['category_path'] );
        $attrs = array();
        foreach ( $product['attributes'] as $name => $value ) {
            $aid = $this->ensure_attribute( $name );
            if ( $aid ) $attrs[] = array( 'attribute_id' => $aid, 'value' => $value );
        }
        $row = array(
            'name' => $product['name'],
            'sku' => $product['sku'],
            'brand_id' => 0,
            'category_id' => $category_id,
            'short_description' => $product['short_description'],
            'description' => $product['description'],
            'price' => $product['price'],
            'currency' => $product['currency'],
            'stock_status' => 'unknown',
            'status' => in_array( ( $options['status'] ?? 'publish' ), array( 'draft', 'publish' ), true ) ? $options['status'] : 'publish',
            'attributes' => $attrs,
        );
        $id = $this->db->save_product( $row, $existing ? (int) $existing->id : 0 );
        if ( is_wp_error( $id ) || ! $id ) return is_wp_error( $id ) ? $id : new WP_Error( 'save_failed', 'Не удалось сохранить товар.' );
        $this->db->wpdb->delete( $this->db->tables['product_images'], array( 'product_id' => $id ), array( '%d' ) );
        foreach ( array_slice( $product['images'], 0, 20 ) as $i => $url ) {
            $attachment = $this->media_from_url( $url, $id );
            if ( $attachment ) {
                $this->db->wpdb->insert( $this->db->tables['product_images'], array( 'product_id' => $id, 'attachment_id' => $attachment, 'sort_order' => $i ) );
                if ( 0 === $i ) $this->db->wpdb->update( $this->db->tables['products'], array( 'image_id' => $attachment ), array( 'id' => $id ) );
            }
        }
        return array( 'id' => $id, 'created' => ! $existing );
    }

    private function find_product( $sku ) {
        if ( ! $sku ) return null;
        return $this->db->wpdb->get_row( $this->db->wpdb->prepare( "SELECT * FROM {$this->db->tables['products']} WHERE sku=%s LIMIT 1", $sku ) );
    }

    private function ensure_category_path( $path ) {
        $parent = 0;
        foreach ( array_filter( array_map( 'sanitize_text_field', (array) $path ) ) as $name ) {
            $row = $this->db->wpdb->get_row( $this->db->wpdb->prepare( "SELECT * FROM {$this->db->tables['categories']} WHERE name=%s AND parent_id=%d LIMIT 1", $name, $parent ) );
            if ( $row ) $id = (int) $row->id;
            else $id = $this->db->save_category( array( 'name' => $name, 'parent_id' => $parent, 'status' => 'publish' ) );
            if ( is_wp_error( $id ) || ! $id ) break;
            $parent = (int) $id;
        }
        return $parent;
    }

    private function ensure_attribute( $name ) {
        $name = sanitize_text_field( $name ); if ( ! $name ) return 0;
        $row = $this->db->wpdb->get_row( $this->db->wpdb->prepare( "SELECT * FROM {$this->db->tables['attributes']} WHERE name=%s LIMIT 1", $name ) );
        if ( $row ) return (int) $row->id;
        return (int) $this->db->save_attribute( array( 'name' => $name, 'type' => 'text' ) );
    }

    private function parse_specifications( $html ) {
        $out = array();
        if ( ! $html || ! class_exists( 'DOMDocument' ) ) return $out;
        libxml_use_internal_errors( true );
        $dom = new DOMDocument(); $dom->loadHTML( '<?xml encoding="UTF-8">' . $html ); libxml_clear_errors();
        $xp = new DOMXPath( $dom );
        foreach ( $xp->query( '//table//tr' ) as $tr ) {
            $cells = array(); foreach ( $xp->query( './th|./td', $tr ) as $cell ) $cells[] = trim( preg_replace( '/\s+/u', ' ', $cell->textContent ) );
            if ( count( $cells ) < 2 ) continue;
            $key = array_shift( $cells ); $value = implode( ' | ', array_filter( $cells ) );
            if ( $key && $value && mb_strlen( $key ) < 190 ) $out[ $key ] = $value;
        }
        return $out;
    }

    private function infer_category_from_url( $url ) {
        $path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
        $parts = array_values( array_filter( explode( '/', $path ) ) );
        if ( count( $parts ) < 2 ) return '';
        $parts = array_slice( $parts, 0, -1 );
        $parts = array_map( function( $v ) { return ucwords( str_replace( array( '-', '_' ), ' ', sanitize_title( $v ) ) ); }, $parts );
        return end( $parts );
    }

    private function price( $value ) { $value = str_replace( array( ' ', '\u{00A0}' ), '', $value ); $value = str_replace( ',', '.', $value ); return is_numeric( $value ) ? (float) $value : null; }
    private function clean_header( $value ) { return sanitize_key( preg_replace( '/^\xEF\xBB\xBF/', '', trim( (string) $value ) ) ); }

    private function media_from_url( $url, $post_id ) {
        if ( ! $url ) return 0;
        require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = download_url( esc_url_raw( $url ), 30 ); if ( is_wp_error( $tmp ) ) return 0;
        $name = wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ); if ( ! $name ) $name = 'ruspic-import.jpg';
        $id = media_handle_sideload( array( 'name' => sanitize_file_name( $name ), 'tmp_name' => $tmp ), $post_id );
        if ( is_wp_error( $id ) ) { @unlink( $tmp ); return 0; }
        return (int) $id;
    }
}
