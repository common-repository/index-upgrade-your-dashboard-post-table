<?php
require_once(dirname(dirname(__FILE__)) . '/classes/JSON_Decoder.php');

if(!class_exists('Controller__BoltIndex')) {
    class Controller__BoltIndex extends WP_REST_Controller {
        function __construct() {
            $this->types = get_post_types(array(), 'objects');

            $this->register_routes();
        }

        private function queryTaxonomies($type) {
            global $wpdb;
            global $wp_taxonomies;

            $taxonomies = array();

            $taxonomy_objects = array_filter($wp_taxonomies, function($item) use ($type) {
                return array_search($type, $item->object_type) !== false;
            });

            foreach($taxonomy_objects as $taxonomy) {
                $taxonomies[] = array(
                    'id' => $taxonomy->name,
                    'label' => $taxonomy->labels->name,
                    'type' => 'taxonomy',
                    'rest_base' => $taxonomy->rest_base,
                );
            }

            return $taxonomies;
        }

        private function queryMetaKeys($type) {
            global $wpdb;

            if(get_transient("{$type}_meta_keys")) {
                return array_values(get_transient("{$type}_meta_keys"));
            } else {
                $query = "
                    SELECT DISTINCT($wpdb->postmeta.meta_key) 
                    FROM $wpdb->posts 
                    LEFT JOIN $wpdb->postmeta 
                    ON $wpdb->posts.ID = $wpdb->postmeta.post_id 
                    WHERE $wpdb->posts.post_type = '%s'
                ";

                $meta_keys = $wpdb->get_col($wpdb->prepare($query, $type));
                $meta_keys = array_filter($meta_keys, function($key) {
                    return $key != '' && strpos($key, '_wp') === false && strpos($key, '_oembed') === false;
                });

                $meta_keys = array_values($meta_keys);
                $meta_keys = array_map(function($meta) {
                    $label = str_replace('_', ' ', $meta);
                    $label = str_replace('-', ' ', $label);
                    $label = trim($label);
                   
                    return array(
                        'id' => $meta,
                        'type' => 'meta',
                        'label' => $label,
                    );
                }, $meta_keys); 

                set_transient("{$type}_meta_keys", $meta_keys, 60*15); // set a 15 minute expiration to avoid lots of costly queries

                return array_values($meta_keys);
            }

            return array();
        }

        

        public function getMetaKeys($request) {
            return rest_ensure_response($this->queryMetaKeys($request['id']));
        }

        public function getTaxonomies($request) {
            return rest_ensure_response($this->queryTaxonomies($request['id']));
        }

        public function getTaxonomy($request) {
            if($request['id']) {
                if($request['term']) {
                    $term = get_term($request['term'], $request['id']);
                    
                    if($term) {
                        return rest_ensure_response($term);
                    }
                    
                    return new WP_Error(
                        "invalid_term",
                        "Empty Term.",
                        null
                    );
                } else {
                    $terms = get_terms(array(
                        'taxonomy' => $request['id'],
                        'hide_empty' => false,
                        'number' => $request['per_page'], // 20
                        'offset' => ($request['page'] - 1) *  $request['per_page']
                    ));

                    if(!is_wp_error($terms)) {
                        return array_values($terms);
                    }

                    return new WP_Error(
                        "no_more_pages",
                        "There are no more terms.",
                        null
                    );

                }
            }

            return new WP_Error(
                "no_taxonmy_defined",
                "Please provide a valid taxonomy.",
                null
            );
        }

        public function getPostFormat($request) {
            $format = get_term($request['id'], 'post_format');
            
            return rest_ensure_response(
                $format
            );
        }

        public function getUserPermissions() {
            // user permissions
            $user = get_userdata(get_current_user_id());
            $current_user_caps = array();

            if(is_object($user)) {
                $current_user_caps = $user->allcaps;
                
                return rest_ensure_response(
                    array(
                        'permissions' => $current_user_caps,
                        'user' => get_current_user_id(),
                    )
                );
            }

            return new WP_Error(
                'rest_forbidden',
				'Sorry, you are not allowed to do that.',
				array( 'status' => 401 )
            );
        }

        public function getSettingsFileData($type = null) {
            $json_decoder = new BLT_JSON_Decode();
            
            if(file_get_contents(BLT_SCHEMA_FILE)) {
                $settings = $json_decoder->decode(file_get_contents(BLT_SCHEMA_FILE));

                if($type) {
                    return array_values(array_filter($settings, function($object) use ($type) {
                        return $object->post_type == $type;
                    }));
                }

                return $settings;
            }

            return array();
        }

        public function getSettings($request) {
            print_r($request['nonce']);

            $post_types = get_post_types(
                            array(
                                'show_in_rest' => true,
                                'show_in_menu' => true,
                            ),
                            'objects'
                        );

            // filter out attachments
            $post_types = array_filter($post_types, function($type) {
                return $type->name !== 'attachment';
            });

            $post_types = array_map(function($type) {
                return array(
                    'id' => $type->name,
                    'name' => $type->label,
                );
            }, $post_types);

            return rest_ensure_response(
                array(
                    'post_types' => array_values($post_types),
                    'settings' => $this->getSettingsFileData(),
                )
            );
        }


        public function updateSettings($request) {
            if(file_exists(BLT_SCHEMA_FILE)) {
                file_put_contents(BLT_SCHEMA_FILE, $request->get_body());

                return rest_ensure_response(
                    $request->get_body()
                );
            }

            return new WP_Error(
                'error',
                'Something went wrong',
                array('status' => 500)
            );
        }

        public function register_routes() {
            $version = '1';
            $namespace = 'bolt-index/v' . $version;

            register_rest_route( 
                $namespace,
                '/meta-keys/(?P<id>[a-zA-Z0-9-_]+)', 
                array(
                    array(
                        'methods'   => WP_REST_Server::READABLE,
                        'callback'  => array($this, 'getMetaKeys'),
                        'permission_callback' => '__return_true'
                    ),
                )
            );

            register_rest_route( 
                $namespace,
                '/taxonomies/(?P<id>[a-zA-Z0-9-_]+)', 
                array(
                    array(
                        'methods'   => WP_REST_Server::READABLE,
                        'callback'  => array($this, 'getTaxonomies'),
                        'permission_callback' => '__return_true'
                    ),
                )
            );

            register_rest_route( 
                $namespace,
                '/taxonomy/(?P<id>[a-zA-Z0-9-_]+)', 
                array(
                    array(
                        'methods'   => WP_REST_Server::READABLE,
                        'callback'  => array($this, 'getTaxonomy'),
                        'permission_callback' => '__return_true'
                    ),
                )
            );
            
            register_rest_route( 
                $namespace,
                '/get-post-format/(?P<id>[a-zA-Z0-9-_]+)', 
                array(
                    array(
                        'methods'   => WP_REST_Server::READABLE,
                        'callback'  => array($this, 'getPostFormat'),
                        'permission_callback' => '__return_true'
                    ),
                )
            );

            register_rest_route( 
                $namespace,
                '/user-permissions', 
                array(
                    array(
                        'methods'   => WP_REST_Server::READABLE,
                        'callback'  => array($this, 'getUserPermissions'),
                        'permission_callback' => '__return_true'
                    ),
                )
            );
        }   
    }
}