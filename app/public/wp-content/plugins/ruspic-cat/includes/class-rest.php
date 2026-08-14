<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class RUSPIC_Cat_REST {
    private $db; private $ns='ruspic/v1';
    public function __construct($db){$this->db=$db;add_action('rest_api_init',array($this,'routes'));}
    public function routes(){
        register_rest_route($this->ns,'/products',array('methods'=>'GET','callback'=>array($this,'products'),'permission_callback'=>'__return_true','args'=>array('search'=>array('type'=>'string'),'brand_id'=>array('type'=>'integer'),'category_id'=>array('type'=>'integer'),'limit'=>array('type'=>'integer','default'=>20),'offset'=>array('type'=>'integer','default'=>0))));
        register_rest_route($this->ns,'/products/(?P<id>\d+)',array('methods'=>'GET','callback'=>array($this,'product'),'permission_callback'=>'__return_true'));
        register_rest_route($this->ns,'/brands',array('methods'=>'GET','callback'=>array($this,'brands'),'permission_callback'=>'__return_true'));
        register_rest_route($this->ns,'/categories',array('methods'=>'GET','callback'=>array($this,'categories'),'permission_callback'=>'__return_true'));
        register_rest_route($this->ns,'/attributes',array('methods'=>'GET','callback'=>array($this,'attributes'),'permission_callback'=>'__return_true'));
        register_rest_route($this->ns,'/orders',array('methods'=>'POST','callback'=>array($this,'create_order'),'permission_callback'=>'__return_true'));
    }
    public function products($r){return rest_ensure_response(array('items'=>$this->db->get_products($r->get_params()),'count'=>$this->db->count('products')));}
    public function product($r){$p=$this->db->get_product((int)$r['id']);if(!$p)return new WP_Error('not_found','Товар не найден',array('status'=>404));return rest_ensure_response($p);}
    public function brands($r){return rest_ensure_response(array('items'=>$this->db->get_brands(array('limit'=>100))));}
    public function categories($r){return rest_ensure_response(array('items'=>$this->db->get_all_categories()));}
    public function attributes($r){return rest_ensure_response(array('items'=>$this->db->get_attributes()));}
    public function create_order($r){$data=$r->get_json_params();$name=sanitize_text_field($data['customer']['name']??'');$items=is_array($data['items']??null)?$data['items']:array();if(!$name||!$items)return new WP_Error('invalid_order','Нужны имя клиента и хотя бы один товар.',array('status'=>400));$fingerprint=wp_hash(sanitize_text_field($_SERVER['REMOTE_ADDR']??'unknown'));$key='ruspic_order_rate_'.$fingerprint;if(get_transient($key))return new WP_Error('rate_limited','Повторите отправку немного позже.',array('status'=>429));set_transient($key,1,30);$id=$this->db->create_order($data['customer'],$items);if(!$id)return new WP_Error('order_failed','Не удалось создать заявку.',array('status'=>500));return new WP_REST_Response(array('success'=>true,'order_id'=>$id),201);}
}
