<?php
/** Local integration test for real WordPress revisions, ACF text, galleries and restore guards. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) exit;
$assert = static function( bool $ok, string $message ): void { if ( ! $ok ) throw new RuntimeException($message); echo "PASS: $message\n"; };
$user = get_current_user_id();wp_set_current_user((int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0]);
$posts=[];$images=[];
acf_add_local_field_group(['key'=>'group_pkca_revision_test','title'=>'Revision fixture','fields'=>[
 ['key'=>'field_pkca_revision_root','name'=>'content_repeater','type'=>'repeater','sub_fields'=>[
  ['key'=>'field_pkca_revision_flex','name'=>'flex_content','type'=>'flexible_content','layouts'=>[
   ['key'=>'layout_pkca_revision_test','name'=>'history_test','label'=>'History test','sub_fields'=>[
    ['key'=>'field_pkca_revision_title','name'=>'title','type'=>'text','label'=>'Title'],
    ['key'=>'field_pkca_revision_images','name'=>'images','type'=>'gallery','return_format'=>'array','label'=>'Images'],
   ]],
  ]],
 ]],
]]);
try {
 for($i=0;$i<3;$i++){ $id=wp_insert_attachment(['post_title'=>'PKCA revision image','post_mime_type'=>'image/jpeg','post_status'=>'inherit']);$images[]=$id;update_post_meta($id,'_wp_attached_file','pkca-history-'.$i.'.jpg'); }
 for($i=0;$i<2;$i++){ $id=wp_insert_post(['post_title'=>'PKCA isolated history fixture','post_status'=>'draft','post_type'=>'page']);$posts[]=$id;update_field('field_pkca_revision_root',[['flex_content'=>[['acf_fc_layout'=>'history_test','title'=>'Original title','images'=>array_slice($images,0,2)]]]],$id); }
 $id=$posts[0];
 $next=[$images[2],$images[0]];
 $queued=PKCA_Content::add_changes($id,[['section'=>1,'field_path'=>['title'],'type'=>'text','value'=>'Changed title'],['section'=>1,'field_path'=>['images'],'type'=>'gallery','value'=>$next]]);
 $assert(!is_wp_error($queued),'Text and gallery edits queue together');
 $published=PKCA_Content::publish($id);if(is_wp_error($published))throw new RuntimeException($published->get_error_message());
 $revision=$published['revision_id'];
 $assert($revision>0 && wp_is_post_revision($revision)===$id,'An ACF-only publish creates a native WordPress revision');
 $target=PKCA_Content::target($id);
 $old=PKCA_Content::saved_rows(array_merge($target,['acf_id'=>$revision]));
 $assert($old[0]['flex_content'][0]['title']==='Original title','Revision retains the original ACF title');
 $assert(array_column($old[0]['flex_content'][0]['images'],'ID')===array_slice($images,0,2),'Revision retains original images and gallery order');
 $listing=PKCA_Revisions::listing($id);$assert($listing['available'] && in_array($revision,array_column($listing['revisions'],'id'),true),'Revision is listed for its own page');
 $comparison=PKCA_Revisions::comparison($id,$revision);
 $assert(!is_wp_error($comparison) && count($comparison['changes'])===2,'Preview shows only the changed title and gallery');
 $assert($comparison['changes'][0]['old_value']==='Changed title' && $comparison['changes'][0]['new_value']==='Original title','Preview compares current content with the selected revision in the right direction');
 $assert(array_column($comparison['changes'][1]['new_images'],'id')===array_slice($images,0,2),'Preview includes revision gallery thumbnails in original order');
 $assert(PKCA_Content::saved_rows($target)[0]['flex_content'][0]['title']==='Changed title' && !PKCA_Content::get_changes($id),'Reading the comparison changes neither saved nor pending content');
 $other=PKCA_Revisions::snapshot(PKCA_Content::target($posts[1]));
 $context=PKCA_Content::inspect($id);
 $invalid_preview=PKCA_Revisions::comparison($id,$other);$assert(is_wp_error($invalid_preview)&&$invalid_preview->get_error_code()==='pkca_revision_invalid','Comparison rejects a revision from another page');
 $same=PKCA_Revisions::snapshot($target);$same_preview=PKCA_Revisions::comparison($id,$same);$assert(!$same_preview['changes'],'Identical revision produces no differences');
 $bad=PKCA_Revisions::restore($id,$other,$context['post']['modified']);$assert(is_wp_error($bad)&&$bad->get_error_code()==='pkca_revision_invalid','Revision from another page cannot be restored');
 $bad=PKCA_Revisions::restore($id,$revision,'stale');$assert(is_wp_error($bad)&&$bad->get_error_code()==='pkca_edit_conflict','Stale restore cannot overwrite newer source');
 $restored=PKCA_Revisions::restore($id,$revision,$context['post']['modified']);if(is_wp_error($restored))throw new RuntimeException($restored->get_error_message());
 $now=PKCA_Content::saved_rows($target);$assert($now[0]['flex_content'][0]['title']==='Original title' && array_column($now[0]['flex_content'][0]['images'],'ID')===array_slice($images,0,2),'Restoring returns the exact original ACF title and gallery');
 $backup=PKCA_Content::saved_rows(array_merge($target,['acf_id'=>$restored['backup_revision']]));
 $assert($backup[0]['flex_content'][0]['title']==='Changed title' && array_column($backup[0]['flex_content'][0]['images'],'ID')===$next,'Restore also preserves the replaced version in a native revision');
 PKCA_Content::add_changes($id,[['section'=>1,'field_path'=>['title'],'type'=>'text','value'=>'Pending edit']]);
 $bad=PKCA_Revisions::restore($id,$revision,PKCA_Content::inspect($id)['post']['modified']);$assert(is_wp_error($bad)&&$bad->get_error_code()==='pkca_revision_pending','Restore cannot silently discard pending edits');
 PKCA_Content::discard($id);
 $native=wp_restore_post_revision($restored['backup_revision']);acf_get_store('values')->reset();
 $assert($native===$id && PKCA_Content::saved_rows($target)[0]['flex_content'][0]['title']==='Changed title','WordPress own revision restore also restores the ACF snapshot');
 wp_set_current_user(0);
 $request=new WP_REST_Request('POST','/pk-content-agent/v1/restore');$request->set_param('post_id',$id);$request->set_param('revision_id',$revision);
 $assert(rest_do_request($request)->get_status()===403,'Restore REST endpoint requires the tool role and editing permission');
} finally {
 wp_set_current_user((int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0]);
 foreach($posts as $id){PKCA_Content::discard($id);wp_delete_post($id,true);}
 foreach($images as $id)wp_delete_attachment($id,true);
 acf_remove_local_field_group('group_pkca_revision_test');wp_set_current_user($user);
}
