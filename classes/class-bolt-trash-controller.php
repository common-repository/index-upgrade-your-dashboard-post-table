<?php
if(!class_exists('Index_Trash_Controller')) {
    class Index_Trash_Controller extends WP_REST_Controller {
        function __construct() {
            $this->register_routes();
        }

        public function requiresValidPostType() {
            return new WP_Error(
				'no_post_type_found',
				'A valid post type is required',
				array( 'status' => 400 )
			);
        }

        public function requiresValidPermission($message) {
            return new WP_Error(
				'requires_higher_permission',
				$message,
				array( 'status' => 400 )
			);
        }

        public function checkPermission($permission, $postType, $post = false) {
            // user permissions
            $user = get_userdata(get_current_user_id());

            if(is_object($user)) {
                if($post) {
                    return current_user_can("{$permission}_{$postType}", $post) || current_user_can("{$permission}_posts", $post);    
                }
                
                return current_user_can("{$permission}_{$postType}") || current_user_can("{$permission}_posts");
            }

            return false;
        }


        public function emptyTrash($request) {
            global $wpdb;

            if(isset($request['id'])) {
                $postType = $request['id'];
                $canEmptyTrash = $this->checkPermission("delete_others", $postType);
                
                if($canEmptyTrash) {
                    $ids = get_posts(array(
                        'post_type' => $postType,
                        'post_status' => 'trash',
                        'posts_per_page' => -1,
                        'fields' => 'ids',
                    ));
    
                    $removed = [];
                    $failed = [];
    
                    foreach($ids as $id) {
                        // actually remove the post
                        if(wp_delete_post($id, true)) {
                            $removed[] = $id;
                        } else {
                            $failed[] = $id;
                        }
                    }
    
                    return rest_ensure_response(
                        array(
                            'removed' => $removed,
                            'failed' => $failed,
                        )
                    );
                }

                return $this->requiresValidPermission("You do not have permission to empty the trash");
            }

            return $this->requiresValidPostType();
        }

        public function verifyCanDeleteOthers($postType, $author) {
            $canDeleteOthers = $this->checkPermission("delete_others", $postType);

            if($canDeleteOthers) {
                if(isset($author)) {
                    return $author;
                } else {
                    return false;
                }
            } else {
                return get_current_user_id();
            }
        }

        public function getDateQuery($before, $after) {
            if(isset($before) && isset($after)) {
                return array(
                    array(
                        'before' => $before,
                        'after' => $after,
                    )
                );
            }

            return false;
        }

        public function getStagedTrash($request) {
            global $wpdb;

            if(isset($request['id'])) {
                $postType = $request['id'];

                $args = array(
                    'tax_query' => isset($request['tax_query']) ? $request['tax_query'] : false,
                    'meta_key' => isset($request['meta_key']) ? $request['meta_key'] : false,
                    'author' => $this->verifyCanDeleteOthers($postType, $request['author']),
                    'date_query' => $this->getDateQuery($request['before'], $request['after']),
                    'post_status' => isset($request['status']) ? $request['status'] : false,
                    'posts_per_page' => -1,
                    'paged' => 1,
                    'post_type' => $postType,
                    's' => isset($request['search']) ? $request['search'] : false,
                );
                
                
                $args = array_filter($args, function($item) {
                    return $item !== false;
                });

                $items = get_posts($args);

                $items = array_map(function($post) {
                    return $post->ID;
                }, $items);

                return rest_ensure_response($items);
            }

            return $this->requiresValidPostType();
        }

        public function getTrashedItems($request) {
            global $wpdb;

            if(isset($request['id'])) {
                $postType = $request['id'];
                $canDeleteOthers = $this->checkPermission("delete_others", $postType);
                
                $args = array(
                    'post_type' => $postType,
                    'post_status' => 'trash',
                    'posts_per_page' => 20,
                    'paged' => 1,
                );

                if(!$canDeleteOthers) {
                    $args['author'] = get_current_user_id();
                }
                
                $trash = new WP_Query($args);

                $response = rest_ensure_response($trash->posts);
                $response->header('X-WP-TotalPages', $trash->max_num_pages);

                return $response;
            }

            return $this->requiresValidPostType();
        }

        public function removeTrashedItems($request) {
            global $wpdb;

            $postType = $request['id'];
            $ids = json_decode($request->get_body());

            $trashed = [];

            foreach($ids as $id) {
                $canDeletePost = $this->checkPermission("delete", $postType, $id);
                
                if($canDeletePost) {
                    // actually remove the post
                    if(wp_delete_post($id, true)) {
                        $trashed[] = $id;
                    }
                }
            }

            if(count($trashed) === 0) {
                return $this->requiresValidPermission("You do not have permission to delete {$postType}s");
            }

            return rest_ensure_response($trashed);
        }

        public function restoreTrashedItems($request) {
            global $wpdb;

            $postType = $request['id'];
            $ids = json_decode($request->get_body());

            $restored = [];

            foreach($ids as $id) {
                $canDeletePost = $this->checkPermission("delete", $postType, $id);

                if($canDeletePost) {
                    if(wp_untrash_post($id)) {
                        $restored[] = $id;
                    }
                }
            }

            if(count($restored) === 0) {
                return $this->requiresValidPermission("You do not have permission to restore {$postType}s");
            }

            return rest_ensure_response($restored);
        }

        public function register_routes() {
            $version = '1';
            $namespace = 'bolt-index/v' . $version;

            register_rest_route(
                $namespace,
                '/trash/(?P<id>[a-zA-Z0-9-_]+)',
                array(
                    array(
                        'methods'   => WP_REST_Server::READABLE,
                        'callback'  => array($this, 'getTrashedItems'),
                        'permission_callback' => function() {
                            return current_user_can('delete_posts');
                        }
                    ),

                    array(
                        'methods'   => WP_REST_Server::EDITABLE,
                        'callback'  => array($this, 'removeTrashedItems'),
                        'permission_callback' => function() {
                            return current_user_can('delete_posts');
                        }
                    ),
                )
            );

            register_rest_route(
                $namespace,
                '/staged-trash/(?P<id>[a-zA-Z0-9-_]+)',
                array(
                    array(
                        'methods'   => WP_REST_Server::READABLE,
                        'callback'  => array($this, 'getStagedTrash'),
                        'permission_callback' => function() {
                            return current_user_can('delete_posts');
                        }
                    ),
                )
            );
           

            register_rest_route(
                $namespace,
                '/empty-trash/(?P<id>[a-zA-Z0-9-_]+)',
                array(
                    array(
                        'methods'   => WP_REST_Server::READABLE,
                        'callback'  => array($this, 'emptyTrash'),
                        'permission_callback' => function() {
                            return current_user_can('delete_posts');
                        }
                    ),
                )
            );

            register_rest_route(
                $namespace,
                '/restore/(?P<id>[a-zA-Z0-9-_]+)',
                array(
                    array(
                        'methods'   => WP_REST_Server::EDITABLE,
                        'callback'  => array($this, 'restoreTrashedItems'),
                        'permission_callback' => function() {
                            return current_user_can('delete_posts');
                        }
                    ),
                )
            );
        }   
    }
}