<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class RUSPIC_Cat_Parser_Import {
    private $db;

    public function __construct( $db ) { $this->db = $db; }

    public function import( $file, $options = array() ) {
        if ( ! $file || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) return new WP_Error( 'invalid_file', 'Файл импорта не получен.' );
        if ( ! empty( $file['error'] ) ) return new WP_Error( 'upload_error', 'Ошибка загрузки файла: ' . (int) $file['error'] );
        if ( strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) !== 'csv' ) return new WP_Error( 'unsupported_format', 'Импорт из парсинга сейчас принимает CSV-файлы.' );
        $fp = fopen( $file['tmp_name'], 'rb' );
        if ( ! $fp ) return new WP_Error( 'file_open_failed', 'Не удалось открыть файл.' );
        $header = fgetcsv( $fp, 0, ';', '"' );
        if ( ! is_array( $header ) ) { fclose( $fp ); return new WP_Error( 'empty_file', 'CSV-файл пуст.' ); }
        $header = array_map( array( $this, 'normalize_header' ), $header );
        $required = array( 'sku', 'name', 'short_description', 'description', 'images', 'regular_price', 'technical_specifications', 'ean13', 'product_url' );
        foreach ( $required as $key ) if ( ! in_array( $key, $header, true ) ) { fclose( $fp ); return new WP_Error( 'missing_column', 'В CSV отсутствует обязательная колонка: ' . $key ); }
        $idx = array_flip( $header );
        $result = array( 'processed' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0 );
        while ( ( $row = fgetcsv( $fp, 0, ';', '"' ) ) !== false ) {
            if ( count( $row ) < count( $header ) ) { $result['failed']++; continue; }
            $result['processed']++;
            $product = $this->row_to_product( $row, $idx, $options );
            if ( is_wp_error( $product ) ) { $result['failed']++; continue; }
            $saved = $this->save_product( $product, $options );
            if ( is_wp_error( $saved ) ) $result['failed']++;
            elseif ( ! empty( $saved['created'] ) ) $result['created']++;
            else $result['updated']++;
            @set_time_limit( 10 );
        }
        fclose( $fp );
        return $result;
    }

    private function row_to_product( $row, $idx, $options ) {
        $get = function( $name ) use ( $row, $idx ) { return isset( $idx[ $name ], $row[ $idx[ $name ] ] ) ? trim( (string) $row[ $idx[ $name ] ] ) : ''; };
        $sku = $get( 'sku' ); $name = $get( 'name' );
        if ( ! $sku || ! $name ) return new WP_Error( 'invalid_row', 'В строке отсутствует SKU или название.' );
        $images = preg_split( '/\r?\n|\s*[,|]\s*/', $get( 'images' ) );
        $images = array_values( array_filter( array_map( 'esc_url_raw', array_map( 'trim', $images ) ) ) );
        $category_path = $this->resolve_category_path( $get, $options );
        return array(
            'sku' => $sku, 'name' => $name, 'short_description' => $get( 'short_description' ), 'description' => $get( 'description' ),
            'price' => $this->price( $get( 'regular_price' ) ), 'currency' => 'RUB', 'images' => $images, 'source_url' => esc_url_raw( $get( 'product_url' ) ),
            'attributes' => $this->parse_specifications( $get( 'technical_specifications' )), 'ean13' => preg_replace( '/\D+/', '', $get( 'ean13' ) ),
            'category_path' => $category_path,
        );
    }

    private function resolve_category_path( $get, $options ) {
        foreach ( array( 'category_path', 'category', 'categories' ) as $column ) {
            if ( $get( $column ) ) return $this->split_category_path( $get( $column ) );
        }
        if ( ! empty( $options['category'] ) ) return array( sanitize_text_field( $options['category'] ) );
        $url = $get( 'product_url' );
        $path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
        $parts = array_values( array_filter( explode( '/', $path ) ) );
        if ( count( $parts ) >= 2 ) {
            $parts = array_slice( $parts, 0, -1 );
            // Use the actual URL hierarchy as the category tree, with readable labels.
            return array_values( array_map( function( $v ) { return sanitize_text_field( ucwords( str_replace( array( '-', '_' ), ' ', $v ) ) ); }, $parts ) );
        }
        return array();
    }

    private function split_category_path( $value ) {
        $parts = preg_split( '/\s*(?:>|\/|\\\\|;)\s*/u', trim( $value ) );
        return array_values( array_filter( array_map( 'sanitize_text_field', $parts ) ) );
    }

    private function save_product( $product, $options ) {
        $existing = $this->find_product( $product['sku'] );
        $category_id = $this->ensure_category_path( $product['category_path'] );
        $attrs = array();
        foreach ( $product['attributes'] as $name => $value ) { $aid = $this->ensure_attribute( $name ); if ( $aid ) $attrs[] = array( 'attribute_id' => $aid, 'value' => $value ); }
        $row = array(
            'name' => $product['name'], 'sku' => $product['sku'], 'brand_id' => 0, 'category_id' => $category_id,
            'short_description' => $product['short_description'], 'description' => $product['description'], 'price' => $product['price'], 'currency' => 'RUB',
            'stock_status' => 'unknown', 'status' => in_array( ( $options['status'] ?? 'publish' ), array( 'draft', 'publish' ), true ) ? $options['status'] : 'publish', 'attributes' => $attrs,
        );
        $id = $this->db->save_product( $row, $existing ? (int) $existing->id : 0 );
        if ( is_wp_error( $id ) || ! $id ) return is_wp_error( $id ) ? $id : new WP_Error( 'save_failed', 'Не удалось сохранить товар.' );
        if ( ! empty( $product['images'] ) ) {
            $this->db->wpdb->delete( $this->db->tables['product_images'], array( 'product_id' => $id ), array( '%d' ) );
            foreach ( array_slice( $product['images'], 0, 20 ) as $i => $url ) {
                $attachment = $this->media_from_url( $url, $id );
                if ( $attachment ) {
                    $this->db->wpdb->insert( $this->db->tables['product_images'], array( 'product_id' => $id, 'attachment_id' => $attachment, 'sort_order' => $i ) );
                    if ( 0 === $i ) $this->db->wpdb->update( $this->db->tables['products'], array( 'image_id' => $attachment ), array( 'id' => $id ) );
                }
            }
        }
        return array( 'id' => $id, 'created' => ! $existing );
    }

    private function find_product( $sku ) { return $sku ? $this->db->wpdb->get_row( $this->db->wpdb->prepare( "SELECT * FROM {$this->db->tables['products']} WHERE sku=%s LIMIT 1", $sku ) ) : null; }

    private function ensure_category_path( $path ) {
        $parent = 0;
        foreach ( array_filter( array_map( 'sanitize_text_field', (array) $path ) ) as $name ) {
            $row = $this->db->wpdb->get_row( $this->db->wpdb->prepare( "SELECT * FROM {$this->db->tables['categories']} WHERE name=%s AND parent_id=%d LIMIT 1", $name, $parent ) );
            $id = $row ? (int) $row->id : $this->db->save_category( array( 'name' => $name, 'parent_id' => $parent, 'status' => 'publish' ) );
            if ( is_wp_error( $id ) || ! $id ) return $parent;
            $parent = (int) $id;
        }
        return $parent;
    }

    private function ensure_attribute( $name ) {
        $name = sanitize_text_field( $name ); if ( ! $name ) return 0;
        $row = $this->db->wpdb->get_row( $this->db->wpdb->prepare( "SELECT * FROM {$this->db->tables['attributes']} WHERE name=%s LIMIT 1", $name ) );
        return $row ? (int) $row->id : (int) $this->db->save_attribute( array( 'name' => $name, 'type' => 'text' ) );
    }

    private function parse_specifications( $html ) {
        $out = array(); if ( ! $html || ! class_exists( 'DOMDocument' ) ) return $out;
        libxml_use_internal_errors( true ); $dom = new DOMDocument(); $dom->loadHTML( '<?xml encoding="UTF-8">' . $html ); libxml_clear_errors(); $xp = new DOMXPath( $dom );
        foreach ( $xp->query( '//table//tr' ) as $tr ) {
            $cells = array(); foreach ( $xp->query( './th|./td', $tr ) as $cell ) $cells[] = trim( preg_replace( '/\s+/u', ' ', $cell->textContent ) );
            if ( count( $cells ) < 2 ) continue;
            $key = array_shift( $cells ); $value = implode( ' | ', array_filter( $cells ) ); if ( $key && $value && mb_strlen( $key ) < 190 ) $out[ $key ] = $value;
        }
        return $out;
    }

    private function price( $value ) { $value = str_replace( array( ' ', "\xC2\xA0" ), '', trim( $value ) ); $value = str_replace( ',', '.', $value ); return is_numeric( $value ) ? (float) $value : null; }
    private function normalize_header( $value ) {
        $value = preg_replace( '/^\xEF\xBB\xBF/', '', trim( (string) $value ) );
        $map = array( 'EAN-13' => 'ean13', 'Product URL' => 'product_url', 'Technical specifications' => 'technical_specifications', 'Regular price' => 'regular_price', 'Short description' => 'short_description' );
        if ( isset( $map[ $value ] ) ) return $map[ $value ];
        return sanitize_key( $value );
    }

    private function media_from_url( $url, $post_id ) {
        require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = download_url( esc_url_raw( $url ), 30 ); if ( is_wp_error( $tmp ) ) return 0;
        $name = wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ) ?: 'ruspic-import.jpg';
        $id = media_handle_sideload( array( 'name' => sanitize_file_name( $name ), 'tmp_name' => $tmp ), $post_id ); if ( is_wp_error( $id ) ) { @unlink( $tmp ); return 0; }
        return (int) $id;
    }
}
