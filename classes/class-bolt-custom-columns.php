<?php
use AIOSEO\Plugin\Common\Models;

$uploadsPath = wp_upload_dir();
$uploadsPath = $uploadsPath['basedir'] . '/blt-index';

require_once('JSON_Decoder.php');

if(!class_exists('Index_Custom_Columns')) {
    class Index_Custom_Columns {
        public $uploads_path = '';
        public $schema = array();
    
        function __construct() {
            $this->init();
        }
    
        public function init() {    
            $decoder = new BLT_JSON_Decode();
            $schema = array();
    
            if(file_exists(BLT_SCHEMA_FILE)) {
                $schema = $decoder->decode(file_get_contents(BLT_SCHEMA_FILE));
            }
    
            foreach($schema as $type) {
                if($type->enabled) {
                    $details = get_post_type_object($type->post_type);
    
                    if($details) {
                        // custom columns for plugins
                        add_filter("blt-manage_edit-{$type->post_type}_columns", array($this, 'addCustomColumns'));
                        add_action("blt-manage_{$type->post_type}_posts_custom_column", array($this, 'manageCustomColumns'), 10, 2);
                    }
                }
            }
        }

        private function renderYoastScoreRank( $rank, $title = '' ) {
            if ( empty( $title ) ) {
                $title = $rank->get_label();
            }
    
            return '<div aria-hidden="true" title="' . esc_attr( $title ) . '" class="' . esc_attr( 'wpseo-score-icon ' . $rank->get_css_class() ) . '"></div><span class="screen-reader-text wpseo-score-text">' . esc_html( $title ) . '</span>';
        }
    
        public function addCustomColumns($columns) {
            if(class_exists('WPSEO_Meta')) {
                $columns['yoast_seo_score'] = array(
                    'label' => 'SEO',
                    'width' => '60px',
                );
    
                $columns['yoast_readability_score'] = array(
                    'label' => 'Readability',
                    'width' => '100px',
                );
            }
            

            if(class_exists('AIOSEO\Plugin\Common\Models\Post')) {
                $columns['aioseo-details'] = array(
                    'label' => 'AIOSEO',
                    'width' => '100px',
                );
            }
    
            return $columns;
        }
    
        public function manageCustomColumns( $column, $post_id )  {
            switch( $column )  {
                case 'yoast_seo_score' :
                    if(class_exists('WPSEO_Meta') && class_exists('WPSEO_Rank')) {
                        $score = (int) WPSEO_Meta::get_value( 'linkdex', $post_id );
                        $rank  = WPSEO_Rank::from_numeric_score( $score );

                        echo $this->renderYoastScoreRank($rank);
                    } else {
                        echo "";
                    }

                    break;
    
                case 'yoast_readability_score' :
                    if(class_exists('WPSEO_Meta') && class_exists('WPSEO_Rank')) {
                        $score = (int) WPSEO_Meta::get_value( 'content_score', $post_id );
                        $rank  = WPSEO_Rank::from_numeric_score( $score );

                        echo $this->renderYoastScoreRank($rank);
                    } else {
                        echo "";
                    }

                    break;
                case 'aioseo-details':
                    if(class_exists('AIOSEO\Plugin\Common\Models\Post')) {
                        $thePost = Models\Post::getPost($post_id);

                        if($thePost->seo_score > 0) {
                            echo "{$thePost->seo_score}/100";
                        } else {
                            echo "";
                        }
                    } else {
                        echo "";
                    }
                    
                    break;
    
                default :
                    break;
            }
        }
    }
}