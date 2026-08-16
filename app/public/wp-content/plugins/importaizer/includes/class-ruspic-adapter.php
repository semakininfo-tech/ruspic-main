<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Importaizer_RUSPIC_Adapter {
    private $db;

    public function available() {
        return class_exists( 'RUSPIC_Cat_DB' );
    }

    public function init() {
        if ( ! $this->available() ) return new WP_Error( 'ruspic_missing', 'RUSPIC Cat не активирован. Сначала активируйте RUSPIC Cat.' );
        if ( ! $this->db ) $this->db = new RUSPIC_Cat_DB();
        return true;
    }

    public function import_product( $product, $options = array() ) {
        $ready = $this->init(); if ( is_wp_error( $ready ) ) return $ready;
        $brand_id = $this->ensure_brand( $product['brand'] ?? '' );
        $category_id = $this->ensure_category_path( $product['category_path'] ?? array() );
        $attributes = array();
        foreach ( (array) ( $product['attributes'] ?? array() ) as $name => $value ) {
            if ( ! $name || ! is_scalar( $value ) ) continue;
            $aid = $this->ensure_attribute( $name );
            if ( $aid ) $attributes[] = array( 'attribute_id' => $aid, 'value' => (string) $value );
        }
        $existing = $this->find_product( $product['sku'] ?? '', $product['source_url'] ?? '' );
        $data = array(
            'name' => $product['name'],
            'sku' => $product['sku'] ?? '',
            'brand_id' => $brand_id,
            'category_id' => $category_id,
            'short_description' => $product['short_description'] ?? '',
            'description' => $product['description'] ?? '',
            'price' => isset( $options['price_multiplier'] ) ? (float) ( $product['price'] ?? 0 ) * (float) $options['price_multiplier'] : $product['price'],
            'currency' => $product['currency'] ?? 'RUB',
            'stock_status' => 'unknown',
            'status' => $options['status'] ?? 'draft',
            'attributes' => $attributes,
        );
        $id = $this->db->save_product( $data, $existing ? (int) $existing->id : 0 );
        if ( is_wp_error( $id ) || ! $id ) return is_wp_error( $id ) ? $id : new WP_Error( 'product_save_failed', 'Не удалось сохранить товар.' );
        $images = $this->download_images( $product['images'] ?? array(), $id );
        if ( $images ) {
            foreach ( $images as $i => $attachment_id ) {
                $this->db->wpdb->insert( $this->db->tables['product_images'], array( 'product_id' => $id, 'attachment_id' => $attachment_id, 'sort_order' => $i ) );
            }
            $this->db->wpdb->update( $this->db->tables['products'], array( 'image_id' => (int) $images[0] ), array( 'id' => $id ) );
        }
        if ( ! empty( $product['documents'] ) ) {
            $this->db->wpdb->delete( $this->db->tables['product_documents'], array( 'product_id' => $id ), array( '%d' ) );
            foreach ( $product['documents'] as $i => $doc ) {
                if ( empty( $doc['url'] ) ) continue;
                $this->db->wpdb->insert( $this->db->tables['product_documents'], array( 'product_id' => $id, 'title' => sanitize_text_field( $doc['title'] ?? 'Документ' ), 'url' => esc_url_raw( $doc['url'] ), 'sort_order' => $i ) );
            }
        }
        return array( 'id' => $id, 'created' => ! $existing, 'images' => count( $images ) );
    }

    private function find_product( $sku, $url ) {
        if ( $sku ) {
            $row = $this->db->wpdb->get_row( $this->db->wpdb->prepare( "SELECT * FROM {$this->db->tables['products']} WHERE sku=%s LIMIT 1", $sku ) );
            if ( $row ) return $row;
        }
        return null;
    }

    private function ensure_brand( $name ) {
        $name = sanitize_text_field( $name ); if ( ! $name ) return 0;
        $row = $this->db->wpdb->get_row( $this->db->wpdb->prepare( "SELECT * FROM {$this->db->tables['brands']} WHERE name=%s LIMIT 1", $name ) );
        if ( $row ) return (int) $row->id;
        return (int) $this->db->save_brand( array( 'name' => $name, 'status' => 'publish' ) );
    }

    private function ensure_category_path( $path ) {
        $parent = 0;
        foreach ( array_filter( array_map( 'sanitize_text_field', (array) $path ) ) as $name ) {
            $row = $this->db->wpdb->get_row( $this->db->wpdb->prepare( "SELECT * FROM {$this->db->tables['categories']} WHERE name=%s AND parent_id=%d LIMIT 1", $name, $parent ) );
            if ( ! $row ) { $id = $this->db->save_category( array( 'name' => $name, 'parent_id' => $parent, 'status' => 'publish' ) ); } else $id = (int) $row->id;
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

    private function download_images( $urls, $product_id ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $ids = array();
        foreach ( array_slice( array_unique( array_filter( (array) $urls ) ), 0, 20 ) as $url ) {
            $tmp = download_url( esc_url_raw( $url ), 30 );
            if ( is_wp_error( $tmp ) ) continue;
            $name = wp_basename( wp_parse_url( $url, PHP_URL_PATH ) );
            if ( ! $name ) $name = 'importaizer-' . $product_id . '.jpg';
            $file = array( 'name' => sanitize_file_name( $name ), 'tmp_name' => $tmp );
            $attachment_id = media_handle_sideload( $file, $product_id );
            if ( is_wp_error( $attachment_id ) ) { @unlink( $tmp ); continue; }
            $ids[] = (int) $attachment_id;
        }
        return $ids;
    }
}
