<?php
/** Run locally: wp eval-file tests/role-access.php. Temporary users/draft are removed. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) exit;
require_once ABSPATH . 'wp-admin/includes/user.php';
$assert = static function( bool $ok, string $message ): void { if ( ! $ok ) throw new RuntimeException( $message ); echo "PASS: $message\n"; };
$plugin = PKCA_Plugin::instance();
$original_user = get_current_user_id();
$allowed = null;
$option_filter = static function() use ( &$allowed ) { return $allowed; };
add_filter( 'pre_option_pkca_allowed_roles', $option_filter );
$users = array();
$post_id = 0;
$custom_role = 'pkca_test_' . strtolower( wp_generate_password( 8, false ) );
add_role( $custom_role, 'PKCA custom test role', array( 'read' => true, 'edit_pages' => true ) );
try {
 foreach ( array( 'editor', 'subscriber', $custom_role ) as $role ) {
  $id = wp_insert_user( array( 'user_login' => 'pkca-test-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => $role ) );
  if ( is_wp_error( $id ) ) throw new RuntimeException( 'Could not create isolated test user' );
  $users[$role] = $id;
 }
 $post_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'PKCA role access test', 'post_author' => $users['editor'] ) );
 wp_set_current_user( $users['editor'] );
 $request = new WP_REST_Request( 'GET' ); $request->set_param( 'post_id', $post_id );
 $rest = new PKCA_REST();
 $assert( $plugin->user_has_access() && true === $rest->can_edit( $request ), 'Unconfigured roles preserve existing editor access' );
 $defaults = $plugin->default_allowed_roles();
 $assert( in_array( 'administrator', $defaults, true ) && in_array( 'editor', $defaults, true ) && ! in_array( 'subscriber', $defaults, true ), 'Settings default to roles with editing capabilities' );
 $assert( array( 'editor' ) === $plugin->sanitize_allowed_roles( array( '', 'editor', 'editor', 'does_not_exist' ) ), 'Role selection removes unknown, empty and duplicate roles' );
 $allowed = array( 'editor' );
 $assert( $plugin->user_has_access() && true === $rest->can_edit( $request ), 'Selected editor can use the tool on an editable page' );
 $allowed = array( 'administrator' );
 $assert( ! $plugin->user_has_access() && is_wp_error( $rest->can_edit( $request ) ), 'Excluded editor cannot use the tool despite WordPress editing rights' );
 foreach ( array( 'context', 'media', 'chat', 'change', 'upload', 'publish', 'discard' ) as $route ) {
  $denied = new WP_REST_Request( in_array( $route, array( 'context', 'media' ), true ) ? 'GET' : 'POST', '/pk-content-agent/v1/' . $route );
  $denied->set_param( 'post_id', $post_id );
  $response = rest_do_request( $denied );
  $assert( 403 === $response->get_status() && 'pkca_role_forbidden' === $response->get_data()['code'], "Excluded role is blocked at REST route: $route" );
 }
 $rows = array( array( 'flex_content' => array( array( 'title' => 'Published value' ) ) ) );
 $assert( $rows === $plugin->apply_preview( $rows, $post_id, array() ) && $rows === $plugin->apply_raw_preview( $rows, $post_id, array() ), 'Excluded role receives no pending frontend preview' );
 wp_dequeue_script( 'pk-content-agent' ); wp_dequeue_style( 'pk-content-agent' );
 $plugin->enqueue_frontend();
 $assert( ! wp_script_is( 'pk-content-agent', 'enqueued' ) && ! wp_style_is( 'pk-content-agent', 'enqueued' ), 'Excluded role loads no frontend agent assets' );
 $allowed = array();
 $assert( ! $plugin->user_has_access(), 'All switches off denies access instead of restoring defaults' );
 $user = wp_get_current_user(); $user->add_role( 'subscriber' );
 $allowed = array( 'subscriber' );
 $assert( $plugin->user_has_access(), 'Multiple roles need only one selected role' );
 wp_set_current_user( $users['subscriber'] );
 $assert( $plugin->user_has_access() && is_wp_error( $rest->can_edit( $request ) ), 'Enabling a role does not grant missing WordPress editing rights' );
 wp_set_current_user( $users[$custom_role] ); $allowed = array( $custom_role );
 $assert( $plugin->user_has_access() && in_array( $custom_role, $plugin->default_allowed_roles(), true ), 'Custom editing roles are supported' );
 wp_set_current_user( 0 );
 $assert( ! $plugin->user_has_access(), 'Visitors never receive access' );
 wp_set_current_user( (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
 $allowed = array();
 ob_start(); $plugin->render_settings_page(); $html = ob_get_clean();
 $assert( str_contains( $html, 'Toegang per gebruikersrol' ) && str_contains( $html, 'role="switch"' ) && str_contains( $html, 'value="' . $custom_role . '"' ), 'Administrators can still configure every role when the tool is disabled for all roles' );
} finally {
 remove_filter( 'pre_option_pkca_allowed_roles', $option_filter );
 wp_set_current_user( $original_user );
 if ( $post_id ) wp_delete_post( $post_id, true );
 foreach ( $users as $id ) wp_delete_user( $id );
 remove_role( $custom_role );
}
