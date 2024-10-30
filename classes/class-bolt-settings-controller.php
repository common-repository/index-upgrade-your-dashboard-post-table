<?php
require_once(dirname(dirname(__FILE__)) . '/classes/JSON_Decoder.php');

if(!class_exists('Index_Settings_Controller')) {
    class Index_Settings_Controller extends WP_REST_Controller {
        function __construct() {
            $this->register_routes();
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

        public function getSettings() {
            $post_types = get_post_types(
                            array(
                                'show_in_menu' => true,
                            ),
                            'objects'
                        );

            // filter out attachments
            $post_types = array_filter($post_types, function($type) {
                return $type->name === 'post';
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
                array(
                    'status' => 500
                )
            );
        }

        public function register_routes() {
            $version = '1';
            $namespace = 'bolt-index/v' . $version;

            register_rest_route(
                $namespace,
                '/settings/',
                array(
                    array(
                        'methods'   => WP_REST_Server::READABLE,
                        'callback'  => array($this, 'getSettings'),
                        'permission_callback' => function() {
                            return current_user_can('manage_options');
                        }
                    ),
                    array(
                        'methods'   => WP_REST_Server::CREATABLE,
                        'callback'  => array($this, 'updateSettings'),
                        'permission_callback' => function() {
                            return current_user_can('manage_options');
                        }
                    )
                )
            );
        }   
    }
}