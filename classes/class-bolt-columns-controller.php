<?php
require_once(dirname(dirname(__FILE__)) . '/classes/JSON_Decoder.php');

if(!class_exists('Index_Columns_Controller')) {
    class Index_Columns_Controller extends WP_REST_Controller {
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
                    'admin_label' => $taxonomy->labels->name,
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
                // AND $wpdb->postmeta.meta_key NOT REGEXP '^_'

                $meta_keys = $wpdb->get_col($wpdb->prepare($query, $type));

                $meta_keys = array_filter($meta_keys, function($key) use ($meta_keys) {
                    $isProtected = preg_match('/^_/', $key);

                    if($isProtected) {
                        // because ACF is annoying and stores duplicate keys with protected and not protected names, 
                        // we prefer the regular version if there are two of the same
                        if(array_search(ltrim($key, '_'), $meta_keys) !== false) {
                            return false;
                        }
                    }

                    // strip out some common fields we know we have no use for (this could eventually be an array of nonallowed list)
                    return 
                        $key != '' &&
                        // $key !== '_edit_lock' && 
                        // $key !== '_edit_last' && 
                        // (strpos($key, '_wp') === false || strpos($key, '_wp') > 0) && 
                        strpos($key, '_oembed') === false;
                });
                
                $meta_keys = array_values(array_map(function($meta)  {
                    $label = str_replace('_', ' ', $meta);
                    $label = str_replace('-', ' ', $label);
                    $label = trim($label);
                   
                    return array(
                        'id' => "_blt_metakey_$meta",
                        'type' => 'meta',
                        'label' => $label,
                        'admin_label' => $label,
                    );
                }, $meta_keys)); 

                set_transient("{$type}_meta_keys", $meta_keys, 60*45); // set a 45 minute expiration to avoid lots of costly queries

                return array_values($meta_keys);
            }

            return array();
        }

        public function getColumns($type) {
            $settings = $this->getSettingsFileData($type);
            
            // user permissions
            $user = get_userdata(get_current_user_id());

            $columns = [[
                'id' => 'blt-preview',
                'type' => 'standard',
                'admin_label' => 'Preview',
            ], [
                "id" => "title",
                "type" => "standard",
                "label" => "Title",
                "admin_label" => "Title"
            ], [
                "id" => "date",
                "type" => "standard",
                "label" => "Date",
                "admin_label" => "Date"
            ]];


            // setup default columns title and date (the order is reset below)
            // $wp_list_table = _get_list_table( 'WP_Posts_List_Table', array('screen' => "edit-{$type}"));
            $custom_columns = apply_filters("blt-manage_edit-{$type}_columns", array()); // $wp_list_table->get_column_info();

            $custom_columns = array_values(array_map(function($key, $value) {
                return array(
                    'id' => $key,
                    'type' => 'custom',
                    'label' => isset($value['label']) ? $value['label'] : strip_tags($value),
                    'admin_label' => isset($value['label']) ? $value['label'] : strip_tags($value),
                    'width' => isset($value['width']) ? $value['width'] : '75px'
                );
            }, array_keys($custom_columns), $custom_columns));

            // $custom_columns = array_filter($custom_columns, function($item) {
            //     return array_search($item['id'], array('cb', 'title' , 'date', 'author', 'comments', 'categories', 'tags')) === false;
            // });

            if(
                current_user_can("edit_{$type}s") || 
                current_user_can("delete_{$type}s") || 
                current_user_can("edit_posts") ||
                current_user_can("delete_posts")
            ) {
                 $columns[] = [
                    'id' => 'blt-checkbox',
                    'type' => 'standard',
                    'admin_label' => 'Checkbox',
                ];
            }
          

            // use post_type_supports to check for author and page template support
            if(post_type_supports($type, 'author')) {
                $columns[] = [
                    'id' => 'author',
                    'type' => 'standard',
                    'label' => 'Author',
                    'admin_label' => 'Author',
                ];
            }

            if(post_type_supports($type, 'page-attributes')) {
                $columns[] = [
                    'id' => 'template',
                    'type' => 'standard',
                    'label' => 'Template',
                    'admin_label' => 'Template',
                ];
            }

            if(post_type_supports($type, 'thumbnail')) {
                // custom key that returns actual url to image
                $columns[] = [
                    'id' => 'image_details',
                    'type' => 'standard',
                    'admin_label' => 'Image',
                ];
            }

            if(post_type_supports($type, 'comments')) {
                // custom key that returns actual url to image
                $columns[] = [
                    "id" => "comments",
                    "type" => "standard",
                    "label" => "Comments",
                    "admin_label" => "Comments"
                ];
            }

            // grab relevant post type from types
            $post_type = array_filter($this->types, function($post_type) use ($type) {
                return $post_type->name == $type;
            })[$type];


            if($post_type->hierarchical == true) {
                $columns[] = [
                    'id' => 'parent',
                    'type' => 'standard',
                    'label' => 'Parent',
                    'admin_label' => 'Parent',
                ];
            }


            // go in the order we want using sort_order
            $sort_order = array_flip(['blt-checkbox', 'blt-preview', 'image_details', 'title', 'date', 'author', 'template', 'parent', 'comments']);

            usort($columns, function($a, $b) use ($sort_order) {
                $sort_a = $sort_order[$a['id']];
                $sort_b = $sort_order[$b['id']];

                return ($sort_a < $sort_b) ? -1 : 1;
            });


            // return all options merged
            return array_merge(
                $columns,
                $custom_columns,
                $this->queryMetaKeys($type),
                $this->queryTaxonomies($type)
            );
        }

        public function getAvailableColumns($request) {
            $columns = apply_filters("blt_index_columns_{$request['id']}", $this->getColumns($request['id']));

            return new WP_REST_Response($columns, 200);
        }

        public function getTableColumns($request) {
            // grab post type
            // grab all availalbe columns
            // grab user settings


            // post type
            $type = $request['id'];
            // get all available columns
            $postTypeColumns = $this->getColumns($type);
            $settings = $this->getSettingsFileData($type);

            $table_columns = [];

            if(isset($settings[0]->grid_view) && $settings[0]->grid_view === true) {
                if(
                    current_user_can("edit_{$type}s") || 
                    current_user_can("delete_{$type}s") || 
                    current_user_can("edit_posts") ||
                    current_user_can("delete_posts")
                ) {
                    $table_columns[] = [
                        'id' => 'blt-checkbox',
                        'type' => 'standard',
                    ];
                }

                $table_columns[] = [
                    'id' => 'title',
                    'type' => 'standard',
                    'label' => 'Title',
                ];

                if(post_type_supports($type, 'thumbnail')) {
                    // custom key that returns actual url to image
                    $table_columns[] = [
                        'id' => 'image_details',
                        'type' => 'standard',
                    ];
                }

                if($settings[0]->secondary_content) {
                    $label = str_replace('_blt_metakey_', ' ', $settings[0]->secondary_content->id);
                    $label = str_replace('-', ' ', $label);
                    $label = trim($label);

                    $table_columns[] = [
                        'id' => $settings[0]->secondary_content->id,
                        'type' => strpos($settings[0]->secondary_content->id, '_blt_metakey') !== false ? 'meta' : 'standard',
                        'label' => $label,
                        'format' => isset($settings[0]->secondary_content->format) ? $settings[0]->secondary_content->format : 'text',
                    ];
                } else {
                    $table_columns[] = [
                        'id' => 'date',
                        'type' =>'standard',
                        'label' => false
                    ];
                }

                return rest_ensure_response(
                    $table_columns
                );
            }

            // format setup table columns
            if(isset($settings[0]->columns)) {
                foreach($settings[0]->columns as $column) {
                    $table_columns[$column->id] = [
                        // due to the way settings work, if the enabled field is missing, 
                        // but the field is in settings (ie: a standard field), it means the column is enabled
                        'enabled' => isset($column->enabled) ? $column->enabled : true,
                        'format' => isset($column->format) ? $column->format : 'text',
                        'label' => isset($column->label) ? $column->label : false,
                    ];
                }
            }
            
            // array values is necessary when array_filter grabs random items, as it will key 
            // them if they arent sequential (ex: 1,2,5,6)
            $standard_columns = array_values(array_filter($postTypeColumns, function($column) { 
                return $column['type'] == 'standard';
            }));

                $standard_columns = array_values(array_filter($standard_columns, function($key) use($table_columns) {
                    $id = $key['id'];

                    if(isset($table_columns[$id])) {
                        return $table_columns[$id]['enabled'];
                    }

                    return true;
                }));

                $standard_columns = array_values(array_map(function($key) use($table_columns) {
                    $id = $key['id'];

                    return array_merge(
                        $key,
                        array(
                            'format' => $table_columns[$id]['format'],
                            'label' => $table_columns[$id]['label'] ? $table_columns[$id]['label'] : $key['label']
                        )
                    );
                }, $standard_columns));

            $meta_keys = array_values(array_filter($postTypeColumns, function($column) { 
                return $column['type'] == 'meta';
            }));

            $tax_keys = array_values(array_filter($postTypeColumns,function($column) { 
                return $column['type'] == 'taxonomy';
            }));

            $custom_keys = array_values(array_filter($postTypeColumns,function($column) { 
                return $column['type'] == 'custom';
            }));


            // filter down standard columns with what is in table_columns
            // $standard_keys = array_values(array_filter($standard_keys, function($key) use($table_columns) {
            //     $id = $key['id'];

            //     if(isset($table_columns[$id])) {
            //         return $table_columns[$id]['enabled'];
            //     }

            //     return true;
            // }));
                
            $meta_keys = array_filter($meta_keys, function($key) use($table_columns) {
                $id = $key['id'];

                if(isset($table_columns[$id])) {
                    return $table_columns[$id]['enabled'];
                }

                return false;
            });

                
                $meta_keys = array_map(function($key) use($table_columns) {
                    $id = $key['id'];

                    return array_merge(
                        $key,
                        array(
                            'format' => $table_columns[$id]['format'],
                            'label' => $table_columns[$id]['label'] ? $table_columns[$id]['label'] : $key['label']
                        )
                    );
                }, $meta_keys);

                $meta_keys = array_values($meta_keys);

            $tax_keys = array_filter($tax_keys, function($key) use($table_columns) {
                $id = $key['id'];

                if(isset($table_columns[$id])) {
                    return $table_columns[$id]['enabled'];
                }

                return false;
            });

                $tax_keys = array_map(function($key) use($table_columns) {
                    $id = $key['id'];

                    return array_merge(
                        $key,
                        array(
                            'format' => $table_columns[$id]['format'],
                            'label' => $table_columns[$id]['label'] ? $table_columns[$id]['label'] : $key['label']
                        )
                    );
                }, $tax_keys);

                $tax_keys = array_values($tax_keys);            

            $custom_keys = array_filter($custom_keys, function($key) use($table_columns) {
                $id = $key['id'];

                if(isset($table_columns[$id])) {
                    return $table_columns[$id]['enabled'];
                }

                return true;
            });

                $custom_keys = array_map(function($key) use($table_columns) {
                    $id = $key['id'];

                    return array_merge(
                        $key,
                        array(
                            'label' => $table_columns[$id]['label'] ? $table_columns[$id]['label'] : $key['label'],
                        )
                    );
                }, $custom_keys);

                $custom_keys = array_values($custom_keys);

            // merge together all of the columns
            $columns = apply_filters("blt_index_columns_{$request['id']}", array_merge(
                $standard_columns,
                $meta_keys,
                $tax_keys,
                $custom_keys
            ));

            // sort based off user's order in settings
            if(isset($settings[0]->sort) && count($settings[0]->sort)) {
                $sort_order = array_flip($settings[0]->sort);

                usort($columns, function($a, $b) use ($sort_order) {
                    $sort_a = isset($sort_order[$a['id']]) ? $sort_order[$a['id']] : -1;
                    $sort_b = isset($sort_order[$b['id']]) ? $sort_order[$b['id']] : 0;

                    return ($sort_a < $sort_b) ? -1 : 1;
                });
            }


            return new WP_REST_Response($columns, 200);
        }


        public function getTaxonomies($request) {
            return new WP_REST_Response($this->queryTaxonomies($request['id']), 200);
        }

        public function getUserPermissions() {
            // user permissions
            $user = get_userdata(get_current_user_id());
            $current_user_caps = array();

            if(is_object($user)) {
                $current_user_caps = $user->allcaps;
            }

            return new WP_REST_Response(
                array(
                    'permissions' => $current_user_caps,
                    'user' => get_current_user_id(),
                ),
                200
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

        public function register_routes() {
            $version = '1';
            $namespace = 'bolt-index/v' . $version;

            register_rest_route( 
                $namespace,
                '/columns/(?P<id>[a-zA-Z0-9-_]+)', 
                array(
                    array(
                        'methods'   => WP_REST_Server::READABLE,
                        'callback'  => array($this, 'getAvailableColumns'),
                        'permission_callback' => '__return_true'
                    ),
                )
            );

            register_rest_route( 
                $namespace,
                '/table-columns/(?P<id>[a-zA-Z0-9-_]+)', 
                array(
                    array(
                        'methods'   => WP_REST_Server::READABLE,
                        'callback'  => array($this, 'getTableColumns'),
                        'permission_callback' => '__return_true'
                    ),
                )
            );
        }   
    }
}