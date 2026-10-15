<?php
/** Run with wp eval-file tests/attachment-comparison.php. Uses an isolated transient only. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) exit;

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) throw new RuntimeException( $message );
	echo "PASS: $message\n";
};
$compare = new ReflectionMethod( PKCA_Content::class, 'stored_values_match' );
$sanitize = new ReflectionMethod( PKCA_Content::class, 'sanitize_nested' );
$before = array( array( 'afbeelding' => array( 'ID' => 242, 'url' => '/image.webp', 'author' => '5' ), 'titel' => 'Processtap' ) );
$after = $sanitize->invoke( null, $before );
$assert( $compare->invoke( null, $before, $after, 'repeater' ), 'Sanitized attachment metadata does not change a repeater' );
$changed_image = $after;
$changed_image[0]['afbeelding']['ID'] = 243;
$assert( ! $compare->invoke( null, $before, $changed_image, 'repeater' ), 'Replacing an attachment remains a real change' );
$changed_text = $after;
$changed_text[0]['titel'] = 'Andere processtap';
$assert( ! $compare->invoke( null, $before, $changed_text, 'repeater' ), 'Text edits beside an unchanged attachment remain real changes' );
$assert( ! $compare->invoke( null, array_merge( $before, $changed_image ), array_merge( $changed_image, $before ), 'repeater' ), 'Reordered image rows remain real changes' );
$assert( ! $compare->invoke( null, $before, array(), 'repeater' ), 'Removing an image row remains a real change' );

$target = 'archive:pkca-comparison-test-' . wp_generate_uuid4();
$key_method = new ReflectionMethod( PKCA_Content::class, 'transient_key' );
$key = $key_method->invoke( null, $target );
try {
	$ghost = array( 'id' => 'ghost', 'type' => 'repeater', 'old_value' => $before, 'new_value' => $after );
	$real = array( 'id' => 'real', 'type' => 'gallery', 'old_value' => array( 242 ), 'new_value' => array( 243 ) );
	set_transient( $key, array( $ghost, $real ), 60 );
	$pending = PKCA_Content::get_changes( $target );
	$assert( 1 === count( $pending ) && 'real' === $pending[0]['id'], 'Previously queued metadata-only changes are excluded; the real gallery edit is retained' );
} finally {
	delete_transient( $key );
}

$media_ids = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'posts_per_page' => 1, 'fields' => 'ids' ) );
if ( $media_ids ) {
	$gallery = new ReflectionMethod( PKCA_Content::class, 'gallery_images' );
	$compact = $gallery->invoke( null, $media_ids );
	$preview = $gallery->invoke( null, $media_ids, true );
	$assert( ! isset( $compact[0]['preview_html'] ), 'Unchanged gallery fields do not carry extra preview HTML' );
	$assert( str_contains( $preview[0]['preview_html'], '<img' ), 'Pending gallery changes include a responsive image preview' );
}
