<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Importaizer_Admin {
    private $db; private $analyzer; private $parser; private $ruspic;
    public function __construct( $db, $analyzer, $parser, $ruspic ) {
        $this->db = $db; $this->analyzer = $analyzer; $this->parser = $parser; $this->ruspic = $ruspic;
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
    }
    public function menu() {
        add_menu_page( 'Importaizer', 'Importaizer', 'manage_options', 'importaizer', array( $this, 'dashboard' ), 'dashicons-database-import', 26 );
        add_submenu_page( 'importaizer', 'Импорт поставщика', 'Новый импорт', 'manage_options', 'importaizer', array( $this, 'dashboard' ) );
        add_submenu_page( 'importaizer', 'Поставщики', 'Поставщики', 'manage_options', 'importaizer-suppliers', array( $this, 'suppliers' ) );
        add_submenu_page( 'importaizer', 'История импортов', 'История импортов', 'manage_options', 'importaizer-jobs', array( $this, 'jobs' ) );
    }
    public function assets( $hook ) {
        if ( strpos( $hook, 'importaizer' ) === false ) return;
        wp_enqueue_style( 'importaizer-admin', IMPORTAIZER_URL . 'assets/css/admin.css', array(), IMPORTAIZER_VERSION );
    }
    private function guard() { if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Недостаточно прав.', 'importaizer' ) ); }
    private function header( $title, $subtitle = '' ) { echo '<div class="wrap importaizer-admin"><h1>'.esc_html($title).'</h1>'; if($subtitle) echo '<p class="description">'.esc_html($subtitle).'</p>'; }
    private function footer() { echo '</div>'; }
    private function nonce() { wp_nonce_field( 'importaizer_admin', 'importaizer_nonce' ); }

    public function dashboard() {
        $this->guard();
        $notice = '';
        if ( ! empty( $_POST['importaizer_action'] ) ) {
            check_admin_referer( 'importaizer_admin', 'importaizer_nonce' );
            $action = sanitize_key( $_POST['importaizer_action'] );
            if ( $action === 'analyze' ) $notice = $this->handle_analyze();
            if ( $action === 'preview' ) $notice = $this->handle_preview();
            if ( $action === 'import' ) $notice = $this->handle_import();
        }
        $supplier = ! empty( $_POST['supplier_id'] ) ? $this->db->get_supplier( (int) $_POST['supplier_id'] ) : null;
        $saved = get_user_meta( get_current_user_id(), 'importaizer_last_supplier', true );
        if ( ! $supplier && $saved ) $supplier = $this->db->get_supplier( (int) $saved );
        $analysis = $supplier ? get_user_meta( get_current_user_id(), 'importaizer_analysis_' . $supplier->id, true ) : array();
        $preview = $supplier ? get_user_meta( get_current_user_id(), 'importaizer_preview_' . $supplier->id, true ) : array();
        $this->header( 'Importaizer', 'Визуальный мастер импорта каталога поставщика в RUSPIC Cat.' );
        if ( $notice ) echo '<div class="notice '.(strpos($notice,'Ошибка')===0?'notice-error':'notice-success').' is-dismissible"><p>'.esc_html($notice).'</p></div>';
        echo '<div class="ia-steps"><span class="active">1 Источник</span><span>2 Анализ</span><span>3 Предпросмотр</span><span>4 Импорт</span></div>';
        echo '<div class="ia-card"><h2>1. Укажите поставщика</h2><form method="post">'.$this->nonce().'<input type="hidden" name="importaizer_action" value="analyze"><p><label>Название поставщика<input class="regular-text" name="supplier_name" value="'.esc_attr($supplier->name??'ТехЭлектро').'" required></label></p><p><label>URL сайта<input class="large-text" type="url" name="supplier_url" value="'.esc_attr($supplier->url??'https://techelectro.ru').'" required></label></p><p><button class="button button-primary button-large">Анализировать сайт</button></p></form></div>';
        if ( $analysis ) {
            echo '<div class="ia-card"><h2>2. Результат анализа</h2><div class="ia-stats"><div><b>'.count((array)($analysis['links']??array())).'</b><small>внутренних страниц</small></div><div><b>'.count((array)($analysis['candidate_products']??array())).'</b><small>кандидатов товаров</small></div><div><b>'.(!empty($analysis['has_schema_product'])?'Да':'Нет').'</b><small>Schema Product</small></div><div><b>'.(!empty($analysis['has_tables'])?'Да':'Нет').'</b><small>таблицы</small></div></div><p><strong>Заголовок:</strong> '.esc_html($analysis['title']??'').'</p><p><strong>Sitemap:</strong> '.esc_html(implode(', ', (array)($analysis['sitemaps']??array()))).'</p></div>';
            echo '<div class="ia-card"><h2>3. Предпросмотр</h2><form method="post">'.$this->nonce().'<input type="hidden" name="importaizer_action" value="preview"><input type="hidden" name="supplier_id" value="'.(int)$supplier->id.'"><p>Importaizer сначала загрузит и разберёт до 10 найденных товарных страниц.</p><p><button class="button button-secondary button-large">Показать товары</button></p></form></div>';
        }
        if ( $preview ) {
            echo '<div class="ia-card"><h2>Найденные товары</h2><form method="post">'.$this->nonce().'<input type="hidden" name="importaizer_action" value="import"><input type="hidden" name="supplier_id" value="'.(int)$supplier->id.'"><table class="widefat striped"><thead><tr><th></th><th>Товар</th><th>SKU</th><th>Бренд</th><th>Цена</th><th>Характеристик</th><th>Изображений</th></tr></thead><tbody>'; foreach((array)$preview as $i=>$p){echo '<tr><td><input type="checkbox" name="selected[]" value="'.(int)$i.'" checked></td><td><strong>'.esc_html($p['name']??'').'</strong><br><a target="_blank" href="'.esc_url($p['source_url']??'').'">Источник</a></td><td>'.esc_html($p['sku']??'—').'</td><td>'.esc_html($p['brand']??'—').'</td><td>'.($p['price']!==null?esc_html(number_format((float)$p['price'],2,',',' ').' '.($p['currency']??'RUB')):'—').'</td><td>'.count((array)($p['attributes']??array())).'</td><td>'.count((array)($p['images']??array())).'</td></tr>'; } echo '</tbody></table><p><label>Статус новых товаров <select name="status"><option value="draft">Черновик</option><option value="publish">Опубликован</option></select></label></p><p><label>Коэффициент цены <input type="number" step="0.01" min="0" name="price_multiplier" value="1"></label></p><p><button class="button button-primary button-large">Импортировать выбранные товары</button></p></form></div>';
        }
        $this->footer();
    }

    private function handle_analyze() {
        $url = esc_url_raw( $_POST['supplier_url'] ?? '' ); $name = sanitize_text_field( $_POST['supplier_name'] ?? '' );
        $id = $this->db->save_supplier( array( 'name'=>$name, 'url'=>$url ) ); if ( is_wp_error($id) ) return 'Ошибка: '.$id->get_error_message();
        $result = $this->analyzer->analyze( $url ); if ( is_wp_error($result) ) return 'Ошибка: '.$result->get_error_message();
        $this->db->wpdb->update($this->db->tables['suppliers'],array('last_analyzed_at'=>current_time('mysql')),array('id'=>$id));
        update_user_meta(get_current_user_id(),'importaizer_last_supplier',(int)$id); update_user_meta(get_current_user_id(),'importaizer_analysis_'.$id,$result); delete_user_meta(get_current_user_id(),'importaizer_preview_'.$id); return 'Сайт успешно проанализирован.';
    }

    private function handle_preview() {
        $id=(int)($_POST['supplier_id']??0);$supplier=$this->db->get_supplier($id);if(!$supplier)return 'Ошибка: поставщик не найден.';$analysis=get_user_meta(get_current_user_id(),'importaizer_analysis_'.$id,true);if(!$analysis)return 'Ошибка: сначала выполните анализ.';
        $urls=array_slice((array)($analysis['candidate_products']??array()),0,10);$preview=array();foreach($urls as $url){$p=$this->parser->parse_product($url);if(!is_wp_error($p))$preview[]=$p;}$job=$this->db->create_job($id,'analysis',count($urls));$this->db->update_job($job,array('status'=>'finished','processed'=>count($urls),'finished_at'=>current_time('mysql')));$this->db->log($job,'info','Предпросмотр: разобрано '.count($preview).' страниц.');update_user_meta(get_current_user_id(),'importaizer_preview_'.$id,$preview);return 'Предпросмотр готов: найдено '.count($preview).' товаров.';
    }

    private function handle_import() {
        $id=(int)($_POST['supplier_id']??0);$supplier=$this->db->get_supplier($id);$preview=get_user_meta(get_current_user_id(),'importaizer_preview_'.$id,true);if(!$supplier||!$preview)return 'Ошибка: нет данных для импорта.';$selected=array_map('intval',(array)($_POST['selected']??array()));$status=in_array(($_POST['status']??'draft'),array('draft','publish'),true)?$_POST['status']:'draft';$mult=max(0,(float)($_POST['price_multiplier']??1));$job=$this->db->create_job($id,'import',count($selected));$created=0;$updated=0;$failed=0;$processed=0;foreach($selected as $index){if(!isset($preview[$index]))continue;$product=$preview[$index];$r=$this->ruspic->import_product($product,array('status'=>$status,'price_multiplier'=>$mult));$processed++;if(is_wp_error($r)){$failed++;$this->db->log($job,'error',$r->get_error_message(),array('url'=>$product['source_url']??''));}else{if(!empty($r['created']))$created++;else$updated++;$this->db->save_item($id,$job,$product+array('target_product_id'=>$r['id']));$this->db->log($job,'info',($r['created']?'Создан':'Обновлён').' товар #'.$r['id'],array('sku'=>$product['sku']??''));}}$this->db->update_job($job,array('status'=>'finished','processed'=>$processed,'created_count'=>$created,'updated_count'=>$updated,'failed_count'=>$failed,'finished_at'=>current_time('mysql')));$this->db->wpdb->update($this->db->tables['suppliers'],array('last_imported_at'=>current_time('mysql')),array('id'=>$id));return 'Импорт завершён: создано '.$created.', обновлено '.$updated.', ошибок '.$failed.'.';
    }

    public function suppliers() { $this->guard(); $this->header('Поставщики','Источники данных Importaizer.'); echo '<div class="ia-card"><table class="widefat striped"><thead><tr><th>Название</th><th>URL</th><th>Последний анализ</th><th>Последний импорт</th></tr></thead><tbody>'; foreach($this->db->get_suppliers() as $s) echo '<tr><td><strong>'.esc_html($s->name).'</strong></td><td><a target="_blank" href="'.esc_url($s->url).'">'.esc_html($s->url).'</a></td><td>'.esc_html($s->last_analyzed_at?:'—').'</td><td>'.esc_html($s->last_imported_at?:'—').'</td></tr>'; echo '</tbody></table></div>'; $this->footer(); }
    public function jobs() { $this->guard(); $this->header('История импортов','Результаты запусков Importaizer.'); echo '<div class="ia-card"><table class="widefat striped"><thead><tr><th>#</th><th>Поставщик</th><th>Тип</th><th>Статус</th><th>Обработано</th><th>Создано</th><th>Обновлено</th><th>Ошибки</th></tr></thead><tbody>'; foreach($this->db->get_jobs() as $j) echo '<tr><td>'.(int)$j->id.'</td><td>'.esc_html($j->supplier_name).'</td><td>'.esc_html($j->type).'</td><td>'.esc_html($j->status).'</td><td>'.(int)$j->processed.' / '.(int)$j->total.'</td><td>'.(int)$j->created_count.'</td><td>'.(int)$j->updated_count.'</td><td>'.(int)$j->failed_count.'</td></tr>'; echo '</tbody></table></div>'; $this->footer(); }
}
