<?php
/** Local integration test: isolated draft, removed in finally. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) exit;
$assert = static function( bool $ok, string $message ): void { if ( ! $ok ) throw new RuntimeException($message); echo "PASS: $message\n"; };
$user = get_current_user_id();
wp_set_current_user((int)get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID'))[0]);
$source = url_to_postid(home_url('/voor-assistants/'));
$id = wp_insert_post(array('post_type'=>'page','post_status'=>'draft','post_title'=>'PKCA taxonomy fixture'));
try {
 update_field('content_repeater',get_field('content_repeater',$source),$id);
 $context = PKCA_Content::inspect($id);
 $target = null;
 foreach($context['sections'] as $section)foreach($section['fields'] as $field)if($field['name']==='verhaal_types' && $field['type']==='taxonomy')$target=array($section,$field);
 $assert((bool)$target,'Story taxonomy is editable with the correct transport type');
 [$section,$field]=$target;
 $assert($field['editable'] && $field['multiple'] && count($field['choices'])===3,'Story type choices include all three named options');
 $ids=array_map('intval',array_keys($field['choices']));
 $new=array($ids[0]);
 if($field['value']===$new)$new=array($ids[1]);
 $edit=array('section'=>$section['number'],'field_path'=>$field['path'],'type'=>'taxonomy','value'=>$new);
 $rest = new WP_REST_Request('POST');$rest->set_param('post_id',$id);$rest->set_param('changes',array($edit));
 $result=(new PKCA_REST())->change($rest);
 $assert(!is_wp_error($result),'Manual REST change accepts taxonomy selections');
 $pending=PKCA_Content::get_changes($id);
 $assert(count($pending)===1 && $pending[0]['new_value']===$new,'Only the selected story types are queued');
 $invalid=$edit;$invalid['value']=array((int)get_terms(array('taxonomy'=>'branche','hide_empty'=>false))[0]->term_id);
 $result=PKCA_Content::add_change($id,$invalid);
 $assert(is_wp_error($result) && $result->get_error_code()==='pkca_invalid_term','Terms from another taxonomy are rejected');
 $result=PKCA_Content::publish($id);
 $assert(!is_wp_error($result),'Story type change publishes and verifies ACF values');
 $context=PKCA_Content::inspect($id);
 foreach($context['sections'] as $s)foreach($s['fields'] as $f)if($f['path']===$field['path'] && $s['number']===$section['number'])$saved=$f['value'];
 $assert($saved===$new,'Saved story type IDs survive reading the draft again');
 $edit['value']=array();$result=PKCA_Content::add_change($id,$edit);
 $assert(!is_wp_error($result),'Optional taxonomy selection can be cleared');
} finally { PKCA_Content::discard($id);wp_delete_post($id,true);wp_set_current_user($user); }
