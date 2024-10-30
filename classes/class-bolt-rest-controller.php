<?php
/**
 * REST API: BLT_REST_Controller class
 *
 * @package index
 * @subpackage REST_API
 * @since 1.0.0
 */

/**
 * Extended WP_REST_Controller for managing and interacting with REST API items, while adding security checks and other helper methods.
 *
 * @since 1.0.0
 */

if(!class_exists('BLT_REST_Controller')) {
    class BLT_REST_Controller extends WP_REST_Controller {
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
        
        public function register_routes() {
        }
    }
}