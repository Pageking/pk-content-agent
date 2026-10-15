<?php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) exit;

$schema = array(
	array( 'name' => 'heading', 'type' => 'group', 'instructions' => 'Leave empty to use the post title.', 'sub_fields' => array(
		array( 'name' => 'tag', 'type' => 'select', 'choices' => array( 'h1' => 'h1' ) ),
		array( 'name' => 'text', 'type' => 'text', 'instructions' => 'Individual title guidance.' ),
	) ),
	array( 'name' => 'link', 'type' => 'link', 'instructions' => 'Composite link guidance.' ),
	array( 'name' => 'items', 'type' => 'repeater', 'instructions' => 'Collection guidance.', 'sub_fields' => array(
		array( 'name' => 'content', 'type' => 'group', 'instructions' => 'Row group guidance.', 'sub_fields' => array(
			array( 'name' => 'text', 'type' => 'text', 'instructions' => 'Row field guidance.' ),
		) ),
	) ),
);
$fields = array();
$apply = new ReflectionMethod( PKCA_Content::class, 'apply_field_schema' );
$apply->invokeArgs( null, array( $schema, array(), array(), &$fields, array() ) );
$checks = array(
	'Heading group instructions reach both controls' => 'Leave empty to use the post title.' === ( $fields[0]['group_instructions'][0]['text'] ?? '' ) && $fields[0]['group_instructions'] === $fields[1]['group_instructions'],
	'Individual instructions remain independent' => 'Individual title guidance.' === $fields[1]['instructions'],
	'Composite link instructions survive flattening' => 'Composite link guidance.' === ( $fields[2]['group_instructions'][0]['text'] ?? '' ),
	'Repeater descriptions reach the collection and row fields' => 'Collection guidance.' === $fields[5]['instructions'] && 'Row group guidance.' === ( $fields[5]['row_fields'][0]['group_instructions'][0]['text'] ?? '' ) && 'Row field guidance.' === $fields[5]['row_fields'][0]['instructions'],
);
foreach ( $checks as $message => $passed ) {
	if ( ! $passed ) throw new RuntimeException( $message );
	echo "PASS: $message\n";
}
