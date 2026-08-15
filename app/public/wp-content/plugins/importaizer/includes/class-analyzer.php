<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Importaizer_Analyzer {
    public function analyze( $url ) {
        $url = esc_url_raw( $url );
        if ( ! $this->is_safe_url( $url ) ) return new WP_Error( 'unsafe_url', 'URL поставщика не прошёл проверку безопасности.' );
        $response = wp_safe_remote_get( $url, array( 'timeout' => 20, 'redirection' => 3, 'limit_response_size' => 3 * 1024 * 1024, 'user-agent' => 'Importaizer/' . IMPORTAIZER_VERSION . '; ' . home_url( '/' ) ) );
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 400 ) return new WP_Error( 'http_error', 'Сайт поставщика вернул HTTP ' . $code . '.' );
        $html = wp_remote_retrieve_body( $response );
        if ( ! $html ) return new WP_Error( 'empty_response', 'Сайт поставщика не вернул HTML.' );
        $host = wp_parse_url( $url, PHP_URL_HOST );
        $links = $this->extract_links( $html, $url, $host );
        $jsonld = $this->extract_jsonld( $html );
        $sitemaps = $this->discover_sitemaps( $url, $html );
        $products = array();
        foreach ( $links as $link ) {
            if ( preg_match( '~/(?:sku[_-]?\d+|product|prod|catalog|item)/~iu', $link ) || preg_match( '~/sku[_-]?\d+/?$~iu', $link ) ) $products[] = $link;
        }
        return array(
            'url' => $url,
            'host' => $host,
            'http_code' => $code,
            'title' => $this->title( $html ),
            'links' => $links,
            'candidate_products' => array_values( array_unique( $products ) ),
            'jsonld' => $jsonld,
            'sitemaps' => $sitemaps,
            'has_tables' => strpos( strtolower( $html ), '<table' ) !== false,
            'has_schema_product' => $this->has_schema_product( $jsonld ),
        );
    }

    private function title( $html ) { if ( preg_match( '~<title[^>]*>(.*?)</title>~is', $html, $m ) ) return sanitize_text_field( wp_strip_all_tags( html_entity_decode( $m[1] ) ) ); return ''; }

    private function extract_links( $html, $base, $host ) {
        $out = array();
        if ( class_exists( 'DOMDocument' ) ) {
            libxml_use_internal_errors( true );
            $dom = new DOMDocument();
            $dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
            $xp = new DOMXPath( $dom );
            foreach ( $xp->query( '//a[@href]' ) as $a ) {
                $href = trim( $a->getAttribute( 'href' ) );
                $abs = $this->absolute_url( $href, $base );
                if ( ! $abs || wp_parse_url( $abs, PHP_URL_HOST ) !== $host ) continue;
                $path = wp_parse_url( $abs, PHP_URL_PATH );
                if ( ! $path || preg_match( '~\.(jpg|jpeg|png|gif|webp|svg|pdf|zip|docx?|xlsx?)$~i', $path ) ) continue;
                $out[] = untrailingslashit( $abs );
            }
            libxml_clear_errors();
        }
        return array_values( array_unique( $out ) );
    }

    private function absolute_url( $href, $base ) {
        if ( ! $href || strpos( $href, '#' ) === 0 || preg_match( '~^(javascript:|mailto:|tel:)~i', $href ) ) return '';
        if ( preg_match( '~^https?://~i', $href ) ) return $href;
        $parts = wp_parse_url( $base ); if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return '';
        if ( strpos( $href, '//' ) === 0 ) return $parts['scheme'] . ':' . $href;
        if ( strpos( $href, '/' ) === 0 ) return $parts['scheme'] . '://' . $parts['host'] . $href;
        $path = isset( $parts['path'] ) ? dirname( $parts['path'] ) : '/';
        return $parts['scheme'] . '://' . $parts['host'] . trailingslashit( $path ) . ltrim( $href, '/' );
    }

    private function extract_jsonld( $html ) {
        $out = array();
        if ( preg_match_all( '~<script[^>]+type=[\"\']application/ld\+json[\"\'][^>]*>(.*?)</script>~is', $html, $matches ) ) {
            foreach ( $matches[1] as $raw ) {
                $data = json_decode( trim( html_entity_decode( $raw ) ), true );
                if ( is_array( $data ) ) $out[] = $data;
            }
        }
        return $out;
    }

    private function has_schema_product( $nodes ) {
        foreach ( $nodes as $node ) {
            $type = $node['@type'] ?? '';
            if ( $type === 'Product' || ( is_array( $type ) && in_array( 'Product', $type, true ) ) ) return true;
            if ( isset( $node['@graph'] ) && is_array( $node['@graph'] ) && $this->has_schema_product( $node['@graph'] ) ) return true;
        }
        return false;
    }

    private function discover_sitemaps( $url, $html ) {
        $out = array();
        $host = wp_parse_url( $url, PHP_URL_HOST );
        $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
        $robots = $scheme . '://' . $host . '/robots.txt';
        $response = wp_safe_remote_get( $robots, array( 'timeout' => 10, 'limit_response_size' => 256 * 1024 ) );
        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            foreach ( preg_split( '/\r?\n/', wp_remote_retrieve_body( $response ) ) as $line ) {
                if ( stripos( $line, 'sitemap:' ) === 0 ) $out[] = trim( substr( $line, 8 ) );
            }
        }
        foreach ( array( '/sitemap.xml', '/sitemap_index.xml' ) as $path ) {
            $candidate = $scheme . '://' . $host . $path;
            if ( ! in_array( $candidate, $out, true ) ) $out[] = $candidate;
        }
        return array_values( array_unique( $out ) );
    }

    private function is_safe_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http','https' ), true ) || empty( $parts['host'] ) ) return false;
        $host = $parts['host'];
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) return ! $this->is_private_ip( $host );
        $resolved = gethostbyname( $host );
        return ! $this->is_private_ip( $resolved );
    }

    private function is_private_ip( $ip ) { return ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ); }
}
