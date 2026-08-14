<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class RUSPIC_Cat_DB {
    public $wpdb;
    public $tables = array();

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $p = $wpdb->prefix . 'ruspic_';
        $this->tables = array(
            'brands' => $p . 'brands',
            'categories' => $p . 'categories',
            'attributes' => $p . 'attributes',
            'products' => $p . 'products',
            'product_attributes' => $p . 'product_attributes',
            'product_images' => $p . 'product_images',
            'product_documents' => $p . 'product_documents',
            'orders' => $p . 'orders',
            'order_items' => $p . 'order_items',
        );
    }

    public static function activate() {
        $db = new self();
        $db->install();
        if ( ! get_option( 'ruspic_cat_version' ) ) {
            update_option( 'ruspic_cat_version', RUSPIC_CAT_DB_VERSION );
        }
        flush_rewrite_rules();
    }

    public static function deactivate() { flush_rewrite_rules(); }

    public function install() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $this->wpdb->get_charset_collate();
        $t = $this->tables;
        $sql = array();
        $sql[] = "CREATE TABLE {$t['brands']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            slug varchar(190) NOT NULL,
            description longtext NULL,
            logo_id bigint(20) unsigned NOT NULL DEFAULT 0,
            website_url varchar(255) NULL,
            status varchar(20) NOT NULL DEFAULT 'publish',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY status (status),
            KEY name (name)
        ) {$c};";
        $sql[] = "CREATE TABLE {$t['categories']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
            name varchar(190) NOT NULL,
            slug varchar(190) NOT NULL,
            description longtext NULL,
            image_id bigint(20) unsigned NOT NULL DEFAULT 0,
            sort_order int(11) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'publish',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY parent_id (parent_id),
            KEY status (status),
            KEY name (name)
        ) {$c};";
        $sql[] = "CREATE TABLE {$t['attributes']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            slug varchar(190) NOT NULL,
            unit varchar(50) NULL,
            type varchar(20) NOT NULL DEFAULT 'text',
            sort_order int(11) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'publish',
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY name (name)
        ) {$c};";
        $sql[] = "CREATE TABLE {$t['products']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(255) NOT NULL,
            sku varchar(100) NULL,
            brand_id bigint(20) unsigned NOT NULL DEFAULT 0,
            category_id bigint(20) unsigned NOT NULL DEFAULT 0,
            short_description text NULL,
            description longtext NULL,
            price decimal(18,2) NULL,
            currency varchar(3) NOT NULL DEFAULT 'RUB',
            stock_status varchar(20) NOT NULL DEFAULT 'in_stock',
            stock_qty decimal(18,3) NULL,
            featured tinyint(1) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'publish',
            image_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY sku (sku),
            KEY brand_id (brand_id),
            KEY category_id (category_id),
            KEY status (status),
            KEY name (name),
            KEY featured (featured)
        ) {$c};";
        $sql[] = "CREATE TABLE {$t['product_attributes']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            attribute_id bigint(20) unsigned NOT NULL,
            value_text longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY product_attribute (product_id,attribute_id),
            KEY attribute_id (attribute_id)
        ) {$c};";
        $sql[] = "CREATE TABLE {$t['product_images']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            attachment_id bigint(20) unsigned NOT NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY product_id (product_id)
        ) {$c};";
        $sql[] = "CREATE TABLE {$t['product_documents']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            title varchar(255) NOT NULL,
            url varchar(500) NOT NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY product_id (product_id)
        ) {$c};";
        $sql[] = "CREATE TABLE {$t['orders']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            status varchar(20) NOT NULL DEFAULT 'new',
            customer_name varchar(190) NOT NULL,
            customer_email varchar(190) NULL,
            customer_phone varchar(80) NULL,
            comment text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$c};";
        $sql[] = "CREATE TABLE {$t['order_items']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            product_name varchar(255) NOT NULL,
            sku varchar(100) NULL,
            qty decimal(18,3) NOT NULL DEFAULT 1,
            price decimal(18,2) NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY product_id (product_id)
        ) {$c};";
        foreach ( $sql as $query ) { dbDelta( $query ); }
        update_option( 'ruspic_cat_version', RUSPIC_CAT_DB_VERSION );
    }

    private function now() { return current_time( 'mysql' ); }
    private function slug( $value, $table, $exclude = 0 ) {
        $base = sanitize_title( $value );
        if ( ! $base ) { $base = 'item'; }
        $slug = $base; $i = 2;
        while ( true ) {
            $sql = "SELECT id FROM {$table} WHERE slug = %s";
            $args = array( $slug );
            if ( $exclude ) { $sql .= ' AND id != %d'; $args[] = $exclude; }
            if ( ! $this->wpdb->get_var( $this->wpdb->prepare( $sql, $args ) ) ) { return $slug; }
            $slug = $base . '-' . $i++;
        }
    }

    public function count( $type ) { return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->tables[$type]}" ); }

    public function get_brands( $args = array() ) {
        $limit = min( 100, max( 1, (int) ( $args['limit'] ?? 50 ) ) );
        $offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
        $search = trim( (string) ( $args['search'] ?? '' ) );
        $where = "WHERE status='publish'"; $params = array();
        if ( $search ) { $where .= ' AND name LIKE %s'; $params[] = '%' . $this->wpdb->esc_like( $search ) . '%'; }
        $sql = "SELECT * FROM {$this->tables['brands']} {$where} ORDER BY name ASC LIMIT %d OFFSET %d";
        $params[] = $limit; $params[] = $offset;
        return $this->wpdb->get_results( $this->wpdb->prepare( $sql, $params ) );
    }

    public function get_brand( $id ) { return $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->tables['brands']} WHERE id=%d", $id ) ); }
    public function save_brand( $data, $id = 0 ) {
        $now = $this->now(); $name = sanitize_text_field( $data['name'] ?? '' );
        if ( ! $name ) { return new WP_Error( 'invalid_name', 'Укажите название бренда.' ); }
        $row = array('name'=>$name,'slug'=>$this->slug($data['slug'] ?? $name,$this->tables['brands'],$id),'description'=>wp_kses_post($data['description']??''),'logo_id'=>(int)($data['logo_id']??0),'website_url'=>esc_url_raw($data['website_url']??''),'status'=>in_array(($data['status']??'publish'),array('publish','draft'),true)?$data['status']:'publish','updated_at'=>$now);
        if($id){ $this->wpdb->update($this->tables['brands'],$row,array('id'=>$id)); return $id; }
        $row['created_at']=$now; $this->wpdb->insert($this->tables['brands'],$row); return (int)$this->wpdb->insert_id;
    }
    public function delete_brand($id){ return $this->wpdb->delete($this->tables['brands'],array('id'=>(int)$id),array('%d')); }

    public function get_categories($parent=0){ return $this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$this->tables['categories']} WHERE parent_id=%d AND status='publish' ORDER BY sort_order,name",$parent)); }
    public function get_all_categories(){ return $this->wpdb->get_results("SELECT * FROM {$this->tables['categories']} ORDER BY parent_id,sort_order,name"); }
    public function get_category($id){ return $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->tables['categories']} WHERE id=%d",$id)); }
    public function save_category($data,$id=0){
        $name=sanitize_text_field($data['name']??''); if(!$name)return new WP_Error('invalid_name','Укажите название категории.'); $parent=(int)($data['parent_id']??0); if($parent===$id)$parent=0;
        $row=array('parent_id'=>$parent,'name'=>$name,'slug'=>$this->slug($data['slug']??$name,$this->tables['categories'],$id),'description'=>wp_kses_post($data['description']??''),'image_id'=>(int)($data['image_id']??0),'sort_order'=>(int)($data['sort_order']??0),'status'=>in_array(($data['status']??'publish'),array('publish','draft'),true)?$data['status']:'publish','updated_at'=>$this->now());
        if($id){$this->wpdb->update($this->tables['categories'],$row,array('id'=>$id));return $id;} $row['created_at']=$this->now();$this->wpdb->insert($this->tables['categories'],$row);return (int)$this->wpdb->insert_id;
    }
    public function delete_category($id){ if($this->wpdb->get_var($this->wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['categories']} WHERE parent_id=%d",$id)))return new WP_Error('has_children','Сначала удалите или перенесите дочерние категории.'); return $this->wpdb->delete($this->tables['categories'],array('id'=>(int)$id),array('%d')); }

    public function get_attributes(){return $this->wpdb->get_results("SELECT * FROM {$this->tables['attributes']} WHERE status='publish' ORDER BY sort_order,name");}
    public function save_attribute($data,$id=0){$name=sanitize_text_field($data['name']??'');if(!$name)return new WP_Error('invalid_name','Укажите название характеристики.');$row=array('name'=>$name,'slug'=>$this->slug($data['slug']??$name,$this->tables['attributes'],$id),'unit'=>sanitize_text_field($data['unit']??''),'type'=>in_array(($data['type']??'text'),array('text','number','select','boolean'),true)?$data['type']:'text','sort_order'=>(int)($data['sort_order']??0),'status'=>'publish');if($id){$this->wpdb->update($this->tables['attributes'],$row,array('id'=>$id));return $id;} $this->wpdb->insert($this->tables['attributes'],$row);return (int)$this->wpdb->insert_id;}

    public function get_products($args=array()){
        $limit=min(100,max(1,(int)($args['limit']??20)));$offset=max(0,(int)($args['offset']??0));$search=trim((string)($args['search']??''));$brand=(int)($args['brand_id']??0);$category=(int)($args['category_id']??0);$where="p.status='publish'";$params=array();
        if($search){$where.=' AND (p.name LIKE %s OR p.sku LIKE %s)';$like='%'.$this->wpdb->esc_like($search).'%';$params[]=$like;$params[]=$like;}
        if($brand){$where.=' AND p.brand_id=%d';$params[]=$brand;} if($category){$where.=' AND p.category_id=%d';$params[]=$category;}
        $sql="SELECT p.*,b.name brand_name,c.name category_name FROM {$this->tables['products']} p LEFT JOIN {$this->tables['brands']} b ON b.id=p.brand_id LEFT JOIN {$this->tables['categories']} c ON c.id=p.category_id WHERE {$where} ORDER BY p.featured DESC,p.name ASC LIMIT %d OFFSET %d";$params[]=$limit;$params[]=$offset;return $this->wpdb->get_results($this->wpdb->prepare($sql,$params));
    }
    public function get_product($id){$p=$this->wpdb->get_row($this->wpdb->prepare("SELECT p.*,b.name brand_name,c.name category_name FROM {$this->tables['products']} p LEFT JOIN {$this->tables['brands']} b ON b.id=p.brand_id LEFT JOIN {$this->tables['categories']} c ON c.id=p.category_id WHERE p.id=%d",$id));if(!$p)return null;$p->attributes=$this->wpdb->get_results($this->wpdb->prepare("SELECT pa.*,a.name,a.unit,a.slug FROM {$this->tables['product_attributes']} pa JOIN {$this->tables['attributes']} a ON a.id=pa.attribute_id WHERE pa.product_id=%d ORDER BY a.sort_order,a.name",$id));$p->images=$this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$this->tables['product_images']} WHERE product_id=%d ORDER BY sort_order,id",$id));$p->documents=$this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$this->tables['product_documents']} WHERE product_id=%d ORDER BY sort_order,id",$id));return $p;}
    public function save_product($data,$id=0){
        $name=sanitize_text_field($data['name']??'');if(!$name)return new WP_Error('invalid_name','Укажите название товара.');$now=$this->now();$row=array('name'=>$name,'slug'=>$this->slug($data['slug']??$name,$this->tables['products'],$id),'sku'=>sanitize_text_field($data['sku']??''),'brand_id'=>(int)($data['brand_id']??0),'category_id'=>(int)($data['category_id']??0),'short_description'=>sanitize_textarea_field($data['short_description']??''),'description'=>wp_kses_post($data['description']??''),'price'=>($data['price']??'')!==''?number_format((float)$data['price'],2,'.',''):null,'currency'=>strtoupper(sanitize_text_field($data['currency']??'RUB')),'stock_status'=>in_array(($data['stock_status']??'in_stock'),array('in_stock','out_of_stock','on_order','unknown'),true)?$data['stock_status']:'in_stock','stock_qty'=>($data['stock_qty']??'')!==''?(float)$data['stock_qty']:null,'featured'=>!empty($data['featured'])?1:0,'status'=>in_array(($data['status']??'publish'),array('publish','draft'),true)?$data['status']:'publish','image_id'=>(int)($data['image_id']??0),'updated_at'=>$now);
        if($id){$this->wpdb->update($this->tables['products'],$row,array('id'=>$id));}else{$row['created_at']=$now;$this->wpdb->insert($this->tables['products'],$row);$id=(int)$this->wpdb->insert_id;}
        if(isset($data['attributes'])&&is_array($data['attributes'])){$this->wpdb->delete($this->tables['product_attributes'],array('product_id'=>$id),array('%d'));foreach($data['attributes'] as $a){$aid=(int)($a['attribute_id']??0);if($aid)$this->wpdb->insert($this->tables['product_attributes'],array('product_id'=>$id,'attribute_id'=>$aid,'value_text'=>sanitize_text_field($a['value']??'')));}}
        return $id;
    }
    public function delete_product($id){$this->wpdb->delete($this->tables['product_attributes'],array('product_id'=>(int)$id),array('%d'));$this->wpdb->delete($this->tables['product_images'],array('product_id'=>(int)$id),array('%d'));$this->wpdb->delete($this->tables['product_documents'],array('product_id'=>(int)$id),array('%d'));return $this->wpdb->delete($this->tables['products'],array('id'=>(int)$id),array('%d'));}

    public function create_order($customer,$items){$now=$this->now();$this->wpdb->insert($this->tables['orders'],array('status'=>'new','customer_name'=>sanitize_text_field($customer['name']??''),'customer_email'=>sanitize_email($customer['email']??''),'customer_phone'=>sanitize_text_field($customer['phone']??''),'comment'=>sanitize_textarea_field($customer['comment']??''),'created_at'=>$now));$order_id=(int)$this->wpdb->insert_id;foreach($items as $item){$p=$this->get_product((int)$item['product_id']);if(!$p)continue;$this->wpdb->insert($this->tables['order_items'],array('order_id'=>$order_id,'product_id'=>$p->id,'product_name'=>$p->name,'sku'=>$p->sku,'qty'=>max(0.001,(float)$item['qty']),'price'=>$p->price));}return $order_id;}
    public function get_orders($limit=50){return $this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$this->tables['orders']} ORDER BY id DESC LIMIT %d",min(100,$limit)));}
}
