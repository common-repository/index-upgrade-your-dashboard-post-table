<?php
/*
* Plugin Name: Index – Free Version
* Description: Your tool for a simplified and supercharged WordPress dashboard
* Version: 1.0.1
* Author: Theory Digital LLC
* Author URI: https://www.indexforwp.com/
*/

// define our schema path as we use this in a few different places
$uploadsPath = wp_upload_dir();
$uploadsPath = $uploadsPath['basedir'] . '/blt-index';

DEFINE('BLT_SCHEMA_FOLDER', $uploadsPath);
DEFINE('BLT_SCHEMA_FILE', $uploadsPath . '/schema.json');

require_once('classes/Controller__BoltIndex.php');
require_once('classes/class-bolt-rest-controller.php');
require_once('classes/class-bolt-columns-controller.php');
require_once('classes/class-bolt-trash-controller.php');
require_once('classes/class-bolt-edit-controller.php');
require_once('classes/class-bolt-settings-controller.php');
require_once('classes/class-bolt-custom-columns.php');
require_once('classes/JSON_Decoder.php');

class Bolt__Index {
    public $uploads_path = '';
    public $schema = array();

    function __construct() {
        add_action('admin_menu', array($this, 'setupMenuPages')); 
        add_action('admin_menu', array($this, 'initSettingsPage'));
        add_action('admin_head', array($this, 'hideDefaultPages')); 
        add_action('init', array($this, 'registerCustomColumns'));
        
        add_action('current_screen', array($this, 'setupMenuRedirects')); 
        add_action('admin_enqueue_scripts', array($this, 'renderBackendAssets'));
        add_action('rest_api_init', array($this, 'registerFields'));
        add_action('rest_api_init', array($this, 'registerRoutes'));
        add_action('rest_api_init', array($this, 'changeRESTQueryVars'));
        add_action('admin_notices', array($this, 'showLicenseMessage'));

        register_activation_hook(__FILE__, array($this, 'runSetup'));

        // register_activation_hook(__FILE__, array($this, 'activatePlugin'));
        add_action('admin_footer', array($this, 'activateLicense'));

        add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), array($this, 'provideSettingsLink' ));
    }

    private function hasValidKey() {
        return get_option('blt_index_license') || get_transient('blt_index_license');
    }

    public function activateLicense() {
        $screen = get_current_screen();

        if($screen->id === 'plugins') {
            if(!$this->hasValidKey() && get_transient('show_index_activation_message')) {
                echo '<div id="blt-index-activation" style="position: fixed; height: 100vh; width: 100vw; left: 0; top: 0; z-index: 100000;"></div>';
            }
        }
    }

    public function provideSettingsLink($links) {
        // Build and escape the URL.
        $url = esc_url( add_query_arg(
            'page',
            'blt-index-settings',
            get_admin_url() . 'admin.php'
        ));

        // Create the link.
        $settings_link = "<a href=\"{$url}\">" . __( 'Settings' ) . '</a>';

        
        // Adds the link to the end of the array.
        array_push(
            $links,
            $settings_link
        );

        return $links;
    }

    public function runSetup() {
        set_transient('show_index_activation_message', true, 5);
        
        // initialize the default post types we want to use
        $options = [
            [
                "post_type" => "post",
                "enabled" => true,
                "disable_default_table" => true,
                "default_view" => "table",
                "multi_view" => false,
                "sorted_columns" => false,
                "columns" => []
            ]
        ];
            
        // create a schema file if it doesn't exist
        if(file_exists(BLT_SCHEMA_FOLDER)) {
            if(!file_exists(BLT_SCHEMA_FILE)) {
                $status = file_put_contents(BLT_SCHEMA_FILE, json_encode($options));

                if(!$status) {
                    new WP_Error('could not add schema file');
                } else {
                    // if we know we can create files, then we will also create our html file
                    file_put_contents(BLT_SCHEMA_FOLDER . '/index.html', '');
                }
            }
        } else {
            // recursively make the folders we need
            wp_mkdir_p(BLT_SCHEMA_FOLDER);

            $status = file_put_contents(BLT_SCHEMA_FILE, json_encode($options));

            if(!$status) {
                new WP_Error('could not add schema file');
            } else {
                // if we know we can create files, then we will also create our html file
                file_put_contents(BLT_SCHEMA_FOLDER . '/index.html', '');
            }
        }
    }

    public function showLicenseMessage() {
        $screen = get_current_screen(); 

        if(get_transient('show_index_activation_message')) {
            if($screen->id != 'settings_page_blt-index-settings') {
                $url = esc_url( add_query_arg(
                    'page',
                    'blt-index-settings',
                    get_admin_url() . 'admin.php'
                ));

                echo "<div class=\"notice notice-info\"><p>Thanks for using Index! The free version upgrades your Posts dashboard table. Configure your table <a href=\"{$url}\">here</a></p></div>";
            }
        }
    }

    

    // Enqueue admin assets
    public function renderBackendAssets() {
        $screen = get_current_screen();
        $index_metadata = get_plugin_data(__FILE__);

        wp_enqueue_script(
            'blt-index-plugin',
            plugin_dir_url(__FILE__) . 'dist/js/main.js',
            false,
            $index_metadata['Version'],
            true
        );

        if($screen->id == 'plugins') {
            wp_enqueue_script(
                'blt-index-plugin-activation',
                plugin_dir_url(__FILE__) . 'dist/js/activation.js',
                false,
                $index_metadata['Version'],
                true
            );

                wp_localize_script(
                    'blt-index-plugin-activation',
                    'bltAPApiSettings', 
                    array(
                        'root' => esc_url_raw( rest_url() ),
                        'nonce' => wp_create_nonce( 'wp_rest' ),
                    )
                );
        }

        if($screen->id == 'settings_page_blt-index-settings') {
            wp_enqueue_script(
                'blt-index-settings',
                plugin_dir_url(__FILE__) . 'dist/js/settings.js',
                false,
                $index_metadata['Version'],
                true
            );

                wp_localize_script(
                    'blt-index-settings',
                    'bltAPApiSettings', 
                    array(
                        'root' => esc_url_raw( rest_url() ),
                        'nonce' => wp_create_nonce( 'wp_rest' ),
                    )
                );
        }

        $decoder = new BLT_JSON_Decode();
        $schema = array();
        
        if(file_exists(BLT_SCHEMA_FILE)) {
            $schema = $decoder->decode(file_get_contents(BLT_SCHEMA_FILE));
        }

        $pages = [];

        foreach($schema as $type) {
            if($type->enabled) {
                $pages[] = "blt-index-{$type->post_type}";
            }
        }

        $screenID = explode('_page_', $screen->id);
        
        if(is_array($screenID) && isset($screenID[1])) {
            $screenID = $screenID[1];
        }

        // no reason to affect pages outside of our main archives
        if(in_array($screenID, $pages)) {
            wp_enqueue_script(
                'blt-index-tables',
                plugin_dir_url(__FILE__) . 'dist/js/index.js',
                false,
                time(), // todo: make this a real version number
                true
            );

                wp_localize_script(
                    'blt-index-tables',
                    'bltAPApiSettings', 
                    array(
                        'root' => esc_url_raw( rest_url() ),
                        'nonce' => wp_create_nonce( 'wp_rest' ),
                    )
                );
        }
    }

    public function initSettingsPage() {
        add_submenu_page(
            'options-general.php',
            'Index', 
            'Index', 
            'manage_options', 
            'blt-index-settings',
            array($this, 'createSettingsPage')
        );
    }

    public function setupMenuPages() {
        $decoder = new BLT_JSON_Decode();
        $schema = array();

        if(file_exists(BLT_SCHEMA_FILE)) {
            $schema = $decoder->decode(file_get_contents(BLT_SCHEMA_FILE));
        }

        foreach($schema as $type) {
            if($type->enabled && $type->post_type === 'post') {
                $details = get_post_type_object($type->post_type);
                
                if($details) {
                    $sanitizedLabels = [];
    
                    foreach($details->labels as $key => $value) {
                        $sanitizedLabels[$key] = strtolower(rtrim($value, '.'));
                    }

                    add_submenu_page(
                        'edit.php',
                        'All Posts', 
                        'All Posts', 
                        'edit_posts', 
                        'blt-index-post',
                        function() use ($type, $sanitizedLabels) {
                            $this->createDynamicArchive(
                                array(
                                    'title' => 'Posts', 
                                    'endpoint' => 'posts',
                                    'post_type' => 'post',
                                    'edit_link' => admin_url('post.php'),
                                    'add_new_link' => admin_url('post-new.php'),
                                    'view_style' => isset($type->grid_view) && $type->grid_view === true ? 'grid' : 'table',
                                    'supports' => [
                                        'authors' => post_type_supports($type->post_type, 'author'),
                                        'comments' => post_type_supports($type->post_type, 'comments'),
                                        'page-attributes' => post_type_supports($type->post_type, 'page-attributes'),
                                    ],
                                    'labels' => $sanitizedLabels,
                                )
                            );
                        },
                        1
                    );
                }
            }
        }
    }

    public function hideDefaultPages() {
        $decoder = new BLT_JSON_Decode();
        $schema = array();

        if(file_exists(BLT_SCHEMA_FILE)) {
            $schema = $decoder->decode(file_get_contents(BLT_SCHEMA_FILE));
        }

        $hidden_pages = [];

        foreach($schema as $type) {
            if($type->enabled) {


                if(isset($type->disable_default_table) && $type->disable_default_table === true) {
                    if($type->post_type === 'post') {
                        $hidden_pages[] = 'menu-posts';
                    } else {
                    }
                }
            }
        }

        echo "<style>";

        foreach($hidden_pages as $page) {
            echo "#{$page} .wp-first-item {
                display: none;
            }";
        }
        
        echo "</style>";
    }

    public function setupMenuRedirects() {
        $screen = get_current_screen();

        $decoder = new BLT_JSON_Decode();
        $schema = array();
        
        if(file_exists(BLT_SCHEMA_FILE)) {
            $schema = $decoder->decode(file_get_contents(BLT_SCHEMA_FILE));
        }

        $query_vars = [];

        $taxonomies = array_values(get_taxonomies());

        foreach($_GET as $key => $value) {
            if($key === 'category_name') {
                $value = get_term_by('slug', $value, 'category');
                $tax_query = urlencode(json_encode([
                    [
                        "taxonomy" => "category", 
                        "terms" => [$value->term_id]
                    ]
                ]));

                $query_vars[] = "tax_query={$tax_query}";
            } else if($key === 'tag') {
                $value = get_term_by('slug', $value, 'post_tag');
                $tax_query = urlencode(json_encode([
                    [
                        "taxonomy" => "post_tag", 
                        "terms" => [$value->term_id]
                    ]
                ]));

                $query_vars[] = "tax_query={$tax_query}";
            } else if(in_array($key, $taxonomies) !== false) {
                $value = get_term_by('slug', $value, $key);
                $tax_query = urlencode(json_encode([
                    [
                        "taxonomy" => $key, 
                        "terms" => [$value->term_id]
                    ]
                ]));

                $query_vars[] = "tax_query={$tax_query}";
            } else {
                $query_vars[] = "{$key}={$value}";
            }

        }

        $query_vars = join('&', $query_vars);

        foreach($schema as $type) {
            if($type->enabled) {
                if(
                    (
                        isset($type->disable_default_table) && 
                        ($type->disable_default_table === true) && 
                        ($screen->id == "edit-{$type->post_type}")
                    ) 
                ) {
                    if($type->post_type === 'post') {
                        wp_redirect(admin_url("edit.php?page=blt-index-{$type->post_type}&{$query_vars}"));
                        exit;
                    } else {
                    }
                }
            }
        }
    }

    public function createDynamicArchive($args) {
        global $wpdb;

        $check_for_posts = get_posts(array(
            'post_type' => $args['post_type'],
            'posts_per_page' => 1,
            'paged' => 1,
            'post_status' => array('trash', 'publish', 'draft', 'scheduled'),
        ));

        $actions = apply_filters("blt-{$args['post_type']}-actions", array());
    ?>

        <div class="wrap">
            <?php 
            if(!empty($actions)) {
            ?>
                <ul
                class="blt-index__page-actions">
                    <?php
                    foreach($actions as $action) {
                    ?>

                        <li>
                            <a
                            <?php 
                            foreach($action['atts'] as $attribute => $value) {
                                echo "{$attribute}=\"{$value}\"";
                            }
                            ?>>
                                <?php echo $action['label']; ?>
                            </a>
                        </li>

                    <?php
                    }
                    ?>
                </ul>

            <?php
            }
            ?>

            <h1 style="height: 1px; width:1px; opacity: 0; overflow: hidden; padding: 0; margin: 0;">
                <?php echo $args['title']; ?>
            </h1>

            <div class="blt-index__wrapper">
                <div 
                className="text-black"
                id="blt-index-table" 
                data-post-type="<?php echo $args['post_type']; ?>" 
                <?php echo count($check_for_posts) === 0 ? 'data-empty-post-type' : ''; ?>
                data-endpoint="<?php echo $args['endpoint']; ?>" 
                data-title="<?php echo $args['title']; ?>"
                data-edit-link="<?php echo $args['edit_link']; ?>"
                data-add-new-link="<?php echo $args['add_new_link']; ?>"
                data-view-style="<?php echo $args['view_style']; ?>"
                data-supports="<?php echo htmlentities(json_encode($args['supports'])); ?>"
                data-labels="<?php echo htmlentities(json_encode($args['labels'])); ?>">
                </div>
            </div>
        </div>

    <?php
    }

    public function createSettingsPage() {
    ?>

        <div class="wrap">
            <h1 style="height: 1px; width:1px; opacity: 0; overflow: hidden;">Index Settings</h1>

            <div class="blt-index__wrapper">
                <div 
                id="blt-index-settings">
                </div>
            </div>
        </div>

    <?php
    }

    public function registerCustomColumns() {
        

        new Index_Custom_Columns();
    }

    public function registerRoutes() {
        // settings controller is required regardless of a key, because it's used to activate it!
        new Index_Settings_Controller();
        new Index_Columns_Controller();

        new Controller__BoltIndex();
        new Index_Edit_Controller();
        new Index_Trash_Controller();
    }

    public function registerFields() {
        $decoder = new BLT_JSON_Decode();
        $schema = array();

        $post_types = array();
        
        if(file_exists(BLT_SCHEMA_FILE)) {
            $schema = $decoder->decode(file_get_contents(BLT_SCHEMA_FILE));
        }

        foreach($schema as $type) {
            if($type->enabled) {
                $post_types[] = $type->post_type;
            }
        }

        register_rest_field(
            $post_types,
            'tax_values',
            array(
                'get_callback' => array($this, 'getTaxonomies'),
            )
        );

        register_rest_field( 
            $post_types, 
            'meta_values', 
            array(
                'get_callback' => array($this, 'getAllMeta'),
            )
        );

        register_rest_field( 
            $post_types, 
            'custom_columns', 
            array(
                'get_callback' => array($this, 'getCustomColumns'),
            )
        );

        register_rest_field( 
            $post_types, 
            'author_details', 
            array(
                'get_callback' => array($this, 'getAuthorDetails'),
                'update_callback' => null,
                'schema' => null,
            )
        );

        register_rest_field( 
            $post_types, 
            'parent_details', 
            array(
                'get_callback' => array($this, 'getParentDetails'),
                'update_callback' => null,
                'schema' => null,
            )
        );

        register_rest_field( 
            $post_types, 
            'image_details', 
            array(
                'get_callback' => array($this, 'getFeaturedImage'),
                'update_callback' => null,
                'schema' => null,
            )
        );

        register_rest_field( 
            $post_types, 
            'comment_count', 
            array(
                'get_callback' => array($this, 'getCommentCount'),
                'update_callback' => null,
                'schema' => null,
            )
        );

        register_rest_field( 
            $post_types, 
            'locked', 
            array(
                'get_callback' => array($this, 'checkLockedPost'),
                'update_callback' => null,
                'schema' => null,
            )
        );
    }

    public function getAllMeta($object) {
        if(is_user_logged_in()) {
            $meta = get_post_meta($object['id']);
            $return = [];

            foreach($meta as $id => $value) {
                $return["_blt_metakey_{$id}"] = $value;
            }

            return $return;
        }

        return null;
    }

    public function getCustomColumns($object) {
        if(is_user_logged_in() && isset($object)) {
            $type = get_post_type($object['id']);

            $custom_columns = apply_filters("blt-manage_edit-{$type}_columns", array());

            $actions = array();
    
            foreach($custom_columns as $key => $column) {
                ob_start();
                    do_action("blt-manage_{$type}_posts_custom_column", $key, $object['id']);
                $actions[$key] = ob_get_clean();
            }
            return $actions;
        }

        return null;
    }

    public function getAuthorDetails($object) {
        //get the id of the post object array
        $post = get_post($object['id']);

        $details = array(
            'name' => get_the_author_meta('display_name', $post->post_author),
            'email' => get_the_author_meta('user_email', $post->post_author)
        );

        return $details;
    }

    public function getParentDetails($object) {
        $post = get_post($object['id']);

        if($post->post_parent) {
            return array(
                'title' => get_the_title($post->post_parent),
                'link' => get_permalink($post->post_parent)
            );
        }

        return null;
    }

    public function getFeaturedImage($object) {
        $post = get_post($object['id']);

        if(has_post_thumbnail($post)) {
            return get_the_post_thumbnail_url($post, 'large');
        }

        return null;
    }

    public function getCommentCount($object) {
        return get_comments_number($object['id']);
    }

    public function checkLockedPost($object) {
        $is_locked = false;
        
        if(function_exists('wp_check_post_lock')) {
            $is_locked = wp_check_post_lock($object['id']);
        }

        if($is_locked) {
            return array(
                'name' => get_the_author_meta('display_name', $is_locked),
                'email' => get_the_author_meta('user_email', $is_locked)
            );
        }
        
        return false;
    }

    public function getTaxonomies($object) {
        global $wp_taxonomies;
        
        if(is_user_logged_in()) {
            $id = $object['id'];
            $type = $object['type'];
            $taxonomies = array();

            $taxonomy_objects = array_filter($wp_taxonomies, function($item) use ($type) {
                return array_search($type, $item->object_type) !== false;
            });


            foreach($taxonomy_objects as $taxonomy) {
                $taxonomies[$taxonomy->name] = get_the_terms($id, $taxonomy->name);
            }

            return $taxonomies;
        }

        return null;
    }

    public function formatTaxQuery($taxonomies) {
        $taxQuery = array();

        foreach($taxonomies as $taxonomy) {
            $taxQuery[] = array(
                'taxonomy' => $taxonomy->taxonomy,
                'terms' => $taxonomy->terms,
            );
        }

        return $taxQuery;
    }

    public function changeRESTQueryVars() {
        $decoder = new BLT_JSON_Decode();
        $schema = array();

        $post_types = array();
        
        if(file_exists(BLT_SCHEMA_FILE)) {
            $schema = $decoder->decode(file_get_contents(BLT_SCHEMA_FILE));
        }

        foreach($schema as $type) {
            if($type->enabled) {
                $post_types[] = $type->post_type;

                add_filter( "rest_{$type->post_type}_query", array($this, 'ammendRESTQueryVars'), 10, 2);
                // add_filter( "rest_page_query", array($this, 'ammendRESTQueryVars'), 10, 2);
                
                add_filter( "rest_{$type->post_type}_collection_params", array($this, 'addMetaValueOrder'), 10, 1);
            }
        }
    }

    public function addMetaValueOrder($params) {
        $params['orderby']['enum'][] = 'meta_value';

        return $params;
    }

    public function ammendRESTQueryVars($vars, $request) {
        if(isset($request['meta_key'])) {
            $vars['meta_key'] = $request['meta_key'];
        }

        if(isset($request['tax_query'])) {
            $vars['tax_query'] = json_decode($request['tax_query'], true);
        }

        return $vars;
    }
}

$bolt_index = new Bolt__Index();