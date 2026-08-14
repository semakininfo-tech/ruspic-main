<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class RUSPIC_Cat_Cart {
    private $db;
    public function __construct($db){$this->db=$db;add_action('wp_enqueue_scripts',array($this,'assets'));add_shortcode('ruspic_cart',array($this,'shortcode'));}
    public function assets(){wp_enqueue_style('ruspic-cat-front',RUSPIC_CAT_URL.'assets/css/frontend.css',array(),RUSPIC_CAT_VERSION);wp_enqueue_script('ruspic-cat-front',RUSPIC_CAT_URL.'assets/js/frontend.js',array(),RUSPIC_CAT_VERSION,true);wp_localize_script('ruspic-cat-front','RUSPIC_CAT',array('api'=>esc_url_raw(rest_url('ruspic/v1/')),'nonce'=>wp_create_nonce('wp_rest')));}
    public function shortcode(){ob_start();?><div class="ruspic-cart" data-ruspic-cart><div class="ruspic-cart__items"></div><div class="ruspic-cart__empty">Корзина пока пуста.</div><div class="ruspic-cart__form" hidden><h3>Оформить заявку</h3><form data-ruspic-order><input required name="name" placeholder="Ваше имя"><input name="phone" placeholder="Телефон"><input type="email" name="email" placeholder="E-mail"><textarea name="comment" placeholder="Комментарий"></textarea><button type="submit">Отправить заявку</button><div data-ruspic-order-result></div></form></div></div><?php return ob_get_clean();}
}
