<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
// Данные намеренно не удаляются при удалении плагина. Это защищает каталог от случайной потери данных.
delete_option( 'ruspic_cat_version' );
