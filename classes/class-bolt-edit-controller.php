<?php
if(!class_exists('Index_Edit_Controller')) {
    class Index_Edit_Controller extends BLT_REST_Controller {
        private function verifyCanEditOthers($postType, $author) {
            $canEditOthers = $this->checkPermission("edit_others", $postType);

            if($canEditOthers) {
                if(isset($author)) {
                    return $author;
                } else {
                    return false;
                }
            } else {
                return get_current_user_id();
            }
        }

        public function getFilteredValues($values) {
            $sanitized_values = [];

            foreach($values as $id => $value) {
                // anything that isn't a taxonomy
                if(array_search($id, ['comment_status', 'post_author', 'post_date', 'post_parent']) !== false) {
                    if($id === 'post_date') {
                        $sanitized_values[$id] = sanitize_text_field("{$value}:00");
                    } else if($id === 'template') {
                        // page template needs to be assigned via meta value for custom post types with page attributes
                        $sanitized_values['meta_input']['_wp_page_template'] = sanitize_text_field($value);
                    } else {
                        // default are always passed as an array (since we don't have any text fields)
                        $sanitized_values[$id] = sanitize_text_field($value[0]);
                    }
                }
            }
            
            return $sanitized_values;
        }

        public function assignTaxonomies($post_id, $values) {
            $actions = [];

            foreach($values as $id => $value) {
                // taxonomies
                if(array_search($id, ['comment_status', 'post_author', 'post_date', 'post_parent']) === false) {
                    $actions[] = wp_set_post_terms($post_id, $value, $id);
                }
            }

            return $actions;
        }

        public function getMatchingItems($request) {
            global $wpdb;

            if(isset($request['post_type'])) {
                $postType = $request['post_type'];

                $args = array(
                    'tax_query' => isset($request['tax_query']) ? $request['tax_query'] : false,
                    'meta_key' => isset($request['meta_key']) ? $request['meta_key'] : false,
                    'author' => $this->verifyCanEditOthers($postType, $request['author']),
                    'date_query' => $this->getDateQuery($request['before'], $request['after']),
                    'post_status' => isset($request['status']) ? $request['status'] : ['draft', 'publish', 'future', 'inherit', 'private', 'pending'],
                    'posts_per_page' => -1,
                    'paged' => 1,
                    'post_type' => $postType,
                    's' => isset($request['search']) ? $request['search'] : false,
                );
                
                
                $args = array_filter($args, function($item) {
                    return $item !== false;
                });

                $items = get_posts($args);

                $items = array_values(
                    array_map(function($post) {
                        return $post->ID;
                    }, $items)
                );

                return $items;
            }

            return [];
        }

        public function update($request) {
            global $wpdb;

            $selected = isset($request['selected']) ? $request['selected'] : [];

            if(isset($request['selectedAll']) && $request['selectedAll'] === true) {
                $ids = $this->getMatchingItems($request);
            } else {
                $ids = $selected;
            }

            // $ids may be an empty array if nothing was passed, which is ok, we'll just update zero items
            foreach($ids as $id) {
                $post_id = wp_update_post(
                    array_merge(
                        ['ID' => $id], 
                        $this->getFilteredValues($request['values'])
                    )
                );
                
                if(is_wp_error($post_id)) {
                    return new WP_Error(
                        'something_went_wrong',
                        'Your updates were not saved. Please try again',
                        array( 'status' => 400 )
                    );
                } else {
                    $taxonomies = $this->assignTaxonomies($post_id, $request['values']);

                    array_walk($taxonomies, function($item) {
                        if(is_wp_error($item)) {
                            return new WP_Error(
                                'something_went_wrong',
                                'Some updates were not saved. Please try again',
                                array( 'status' => 400 )
                            );
                        }
                    });
                }
            }

            return rest_ensure_response(
                'Your items were updated successfully',
                200,
            );
        }
        
        public function register_routes() {
            $version = '1';
            $namespace = 'bolt-index/v' . $version;

            register_rest_route(
                $namespace,
                '/update',
                array(
                    array(
                        'methods'   => WP_REST_Server::EDITABLE,
                        'callback'  => array($this, 'update'),
                        'permission_callback' => function() {
                            return current_user_can( 'edit_others_posts' );
                        }
                    ),
                )
            );
        }   
    }
}