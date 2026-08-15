<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Importaizer_Parser {
    public function fetch( $url ) {
        $response = wp_safe_remote_get( $url, array( 'timeout' => 20, 'redirection' => 4, 'limit_response_size' => 5 * 1024 * 1024, 'user-agent' => 'Importaizer/' . IMPORTAIZER_VERSION . '; ' . home_url( '/' ) ) );
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 400 ) return new WP_Error( 'http_error', 'HTTP ' . $code );
        return wp_remote_retrieve_body( $response );
    }

    public function parse_product( $url, $html = '' ) {
        if ( ! $html ) { $html = $this->fetch( $url ); if ( is_wp_error( $html ) ) return $html; }
        $data = array(
            'source_url' => esc_url_raw( $url ),
            'source_id' => '', 'name' => '', 'sku' => '', 'brand' => '', 'price' => null,
            'currency' => 'RUB', 'description' => '', 'short_description' => '',
            'category_path' => array(), 'attributes' => array(), 'images' => array(), 'documents' => array(),
        );
        $json = $this->jsonld_products( $html );
        if ( $json ) $data = array_merge( $data, $this->from_jsonld( $json[0], $url ) );
        $dom = $this->dom( $html );
        if ( $dom ) {
            $xp = new DOMXPath( $dom );
            $data['name'] = $data['name'] ?: $this->text_first( $xp, array( '//h1', '//meta[@property="og:title"]/@content', '//title' ) );
            $data['brand'] = $data['brand'] ?: $this->text_first( $xp, array( '//*[contains(normalize-space(.),"Бренд")]/following::*[1]', '//*[@itemprop="brand"]' ) );
            $data['sku'] = $data['sku'] ?: $this->label_value( $xp, array( 'SKU', 'код товара', 'Артикул' ) );
            $data['price'] = $data['price'] === null ? $this->number_value( $this->label_value( $xp, array( 'РРЦ', 'рекомендуемая розничная цена', 'Цена' ) ) ) : $data['price'];
            $data['description'] = $data['description'] ?: $this->description( $xp );
            $data['images'] = array_values( array_unique( array_merge( $data['images'], $this->images( $xp, $url ) ) ) );
            $data['documents'] = array_values( array_unique( array_merge( $data['documents'], $this->documents( $xp, $url ) ) ) );
            $data['attributes'] = $this->merge_attributes( $data['attributes'], $this->tables( $xp ) );
            $data['category_path'] = $this->breadcrumbs( $xp );
        }
        if ( preg_match( '~/sku[_-]?(\d+)~i', $url, $m ) ) $data['source_id'] = $m[1];
        if ( ! $data['source_id'] && $data['sku'] ) $data['source_id'] = $data['sku'];
        if ( ! $data['name'] ) return new WP_Error( 'product_not_found', 'Не удалось определить название товара.' );
        if ( $data['source_id'] === '' ) $data['source_id'] = md5( $url );
        $data['source_hash'] = hash( 'sha256', wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
        return $data;
    }

    private function dom( $html ) {
        if ( ! class_exists( 'DOMDocument' ) ) return null;
        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        $dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
        libxml_clear_errors();
        return $dom;
    }

    private function jsonld_products( $html ) {
        $out = array();
        if ( preg_match_all( '~<script[^>]+type=[\"\']application/ld\+json[\"\'][^>]*>(.*?)</script>~is', $html, $matches ) ) {
            foreach ( $matches[1] as $raw ) {
                $v = json_decode( trim( html_entity_decode( $raw ) ), true );
                if ( ! is_array( $v ) ) continue;
                $nodes = isset( $v['@graph'] ) && is_array( $v['@graph'] ) ? $v['@graph'] : array( $v );
                foreach ( $nodes as $node ) {
                    if ( isset( $node['@type'] ) && ( $node['@type'] === 'Product' || ( is_array( $node['@type'] ) && in_array( 'Product', $node['@type'], true ) ) ) ) $out[] = $node;
                }
            }
        }
        return $out;
    }

    private function from_jsonld( $p, $url ) {
        $brand = is_array( $p['brand'] ?? null ) ? ( $p['brand']['name'] ?? '' ) : ( $p['brand'] ?? '' );
        $offers = $p['offers'] ?? array();
        if ( isset( $offers[0] ) ) $offers = $offers[0];
        return array(
            'name' => sanitize_text_field( $p['name'] ?? '' ),
            'sku' => sanitize_text_field( $p['sku'] ?? '' ),
            'brand' => sanitize_text_field( $brand ),
            'description' => wp_kses_post( $p['description'] ?? '' ),
            'short_description' => sanitize_textarea_field( wp_strip_all_tags( $p['description'] ?? '' ) ),
            'price' => isset( $offers['price'] ) ? (float) $offers['price'] : null,
            'currency' => strtoupper( sanitize_text_field( $offers['priceCurrency'] ?? 'RUB' ) ),
            'images' => array_values( array_filter( array_map( 'esc_url_raw', (array) ( $p['image'] ?? array() ) ) ) ),
            'source_id' => sanitize_text_field( $p['sku'] ?? '' ),
        );
    }

    private function text_first( $xp, $queries ) {
        foreach ( $queries as $q ) {
            $nodes = $xp->query( $q );
            if ( $nodes && $nodes->length ) {
                $node = $nodes->item( 0 );
                $value = $node instanceof DOMAttr ? $node->value : $node->textContent;
                $value = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $value ) ) );
                if ( $value ) return sanitize_text_field( $value );
            }
        }
        return '';
    }

    private function label_value( $xp, $labels ) {
        foreach ( $labels as $label ) {
            $nodes = $xp->query( "//*[contains(translate(normalize-space(.),'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ','абвгдеёжзийклмнопрстуфхцчшщъыьэюя'), '" . mb_strtolower( $label ) . "')]" );
            foreach ( $nodes as $node ) {
                $text = trim( preg_replace( '/\s+/u', ' ', $node->textContent ) );
                if ( preg_match( '~(?:' . preg_quote( $label, '~' ) . ')\s*[:№]?\s*([^\n]{1,150})~iu', $text, $m ) ) return trim( $m[1] );
                $next = $node->nextSibling;
                if ( $next ) { $v = trim( preg_replace( '/\s+/u', ' ', $next->textContent ) ); if ( $v ) return $v; }
            }
        }
        return '';
    }

    private function number_value( $value ) {
        if ( ! $value ) return null;
        $value = str_replace( array( ' ', '&nbsp;' ), '', $value );
        if ( preg_match( '~(\d+[\d\s]*[\.,]?\d*)~u', $value, $m ) ) return (float) str_replace( ',', '.', $m[1] );
        return null;
    }

    private function description( $xp ) {
        foreach ( array( '//*[@itemprop="description"]', '//*[contains(@class,"description")]', '//*[contains(@class,"product-description")]' ) as $q ) {
            $nodes = $xp->query( $q );
            if ( $nodes && $nodes->length ) return wp_kses_post( trim( $nodes->item(0)->textContent ) );
        }
        return '';
    }

    private function images( $xp, $base ) {
        $out = array();
        foreach ( $xp->query( '//img[@src or @data-src or @data-original]' ) as $img ) {
            foreach ( array( 'data-src','data-original','src' ) as $attr ) {
                if ( $img->hasAttribute( $attr ) ) { $u = $this->absolute( $img->getAttribute( $attr ), $base ); if ( $u ) { $out[] = $u; break; } }
            }
        }
        return array_slice( array_values( array_unique( $out ) ), 0, 30 );
    }

    private function documents( $xp, $base ) {
        $out = array();
        foreach ( $xp->query( '//a[@href]' ) as $a ) {
            $href = $a->getAttribute( 'href' );
            if ( preg_match( '~\.(pdf|step|stp|zip|docx?|xlsx?)($|\?)~i', $href ) ) $out[] = array( 'title' => sanitize_text_field( trim( $a->textContent ) ?: basename( wp_parse_url( $href, PHP_URL_PATH ) ) ), 'url' => $this->absolute( $href, $base ) );
        }
        return $out;
    }

    private function tables( $xp ) {
        $out = array();
        foreach ( $xp->query( '//table//tr' ) as $tr ) {
            $cells = array(); foreach ( $xp->query( './th|./td', $tr ) as $cell ) $cells[] = trim( preg_replace( '/\s+/u', ' ', $cell->textContent ) );
            if ( count( $cells ) < 2 ) continue;
            $first = array_shift( $cells );
            if ( preg_match( '/^(SKU|код|товарные позиции)$/iu', $first ) ) continue;
            if ( count( $cells ) === 1 ) continue;
            $value = implode( ' | ', $cells );
            if ( $first && $value && mb_strlen( $first ) < 190 ) $out[$first] = $value;
        }
        return $out;
    }

    private function merge_attributes( $existing, $new ) { foreach ( $new as $k => $v ) if ( ! isset( $existing[$k] ) ) $existing[$k] = $v; return $existing; }

    private function breadcrumbs( $xp ) {
        $out = array();
        foreach ( $xp->query( '//*[contains(@class,"breadcrumb")]//a|//*[@itemtype="http://schema.org/BreadcrumbList"]//a' ) as $a ) { $v = trim( preg_replace( '/\s+/u', ' ', $a->textContent ) ); if ( $v ) $out[] = sanitize_text_field( $v ); }
        return array_values( array_unique( $out ) );
    }

    private function absolute( $href, $base ) {
        if ( ! $href || preg_match( '~^(data:|javascript:|mailto:)~i', $href ) ) return '';
        if ( preg_match( '~^https?://~i', $href ) ) return $href;
        $p = wp_parse_url( $base ); if ( ! $p || empty( $p['scheme'] ) || empty( $p['host'] ) ) return '';
        if ( strpos( $href, '//' ) === 0 ) return $p['scheme'] . ':' . $href;
        if ( strpos( $href, '/' ) === 0 ) return $p['scheme'] . '://' . $p['host'] . $href;
        return $p['scheme'] . '://' . $p['host'] . trailingslashit( dirname( $p['path'] ?? '/' ) ) . ltrim( $href, '/' );
    }
}
