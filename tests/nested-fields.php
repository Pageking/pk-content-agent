<?php
/** Isolated ACF nested repeater regression; run using wp eval-file. */
if ( ! defined('WP_CLI') || ! WP_CLI ) exit;
$assert=static function(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";};
$user=get_current_user_id();wp_set_current_user((int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0]);
$group=['key'=>'group_pkca_nested_test','title'=>'Nested fixture','fields'=>[['key'=>'field_pkca_nested_root','name'=>'content_repeater','type'=>'repeater','sub_fields'=>[['key'=>'field_pkca_nested_flex','name'=>'flex_content','type'=>'flexible_content','layouts'=>[['key'=>'layout_pkca_nested_test','name'=>'nested_test','label'=>'Pijlers','sub_fields'=>[['key'=>'field_pkca_nested_cards','name'=>'cards','type'=>'repeater','label'=>'Pijlers','sub_fields'=>[
 ['key'=>'field_pkca_nested_title','name'=>'title','type'=>'text','label'=>'Titel'],
 ['key'=>'field_pkca_nested_benefits','name'=>'benefits','type'=>'repeater','label'=>'Pluspunten','min'=>1,'max'=>3,'sub_fields'=>[['key'=>'field_pkca_nested_text','name'=>'text','type'=>'text','label'=>'Tekst']]],
 ['key'=>'field_pkca_nested_image','name'=>'background','type'=>'image','return_format'=>'array','label'=>'Eigen achtergrondafbeelding'],
]]]]]]]]]];
acf_add_local_field_group($group);$id=0;$images=[];
try {
 for($i=0;$i<2;$i++){$image=wp_insert_attachment(['post_title'=>'PKCA nested fixture image','post_mime_type'=>'image/jpeg','post_status'=>'inherit']);$images[]=$image;update_post_meta($image,'_wp_attached_file','pkca-nested-'.$i.'.jpg');}
 $id=wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>'PKCA nested fields test']);
 $rows=[['title'=>'Samen verder','benefits'=>[['text'=>'Persoonlijke aandacht'],['text'=>'Ruimte voor initiatief']],'background'=>$images[0]]];
 update_field('field_pkca_nested_root',[['flex_content'=>[['acf_fc_layout'=>'nested_test','cards'=>$rows]]]],$id);
 $context=PKCA_Content::inspect($id);$fields=$context['sections'][0]['fields'];$cards=$fields[0];
 $nested=array_values(array_filter($cards['row_fields'],static fn($f)=>$f['type']==='repeater'));
 $assert(count($nested)===1 && $nested[0]['row_fields'][0]['label']==='Tekst','Nested pluspoints expose their own field schema');
 $assert(in_array(['cards','0','benefits','0','text'],array_column($fields,'path'),true),'Each pluspoint has an editable full ACF path');
 $next=$cards['value'];$next[0]['benefits'][0]['text']='Echte persoonlijke aandacht';$next[0]['background']=['ID'=>$images[1],'url'=>wp_get_attachment_url($images[1])];
 $queued=PKCA_Content::add_changes($id,[['section'=>1,'field_path'=>['cards'],'type'=>'repeater','value'=>$next]]);if(is_wp_error($queued))throw new RuntimeException($queued->get_error_message());
 $preview=PKCA_Content::inspect($id);$assert(count($preview['changes'])===1,'Editing a nested row queues one parent change');
 PKCA_Content::add_changes($id,[['section'=>1,'field_path'=>['cards','0','benefits','1','text'],'type'=>'text','value'=>'Meer ruimte voor initiatief']]);
 $merged=PKCA_Content::get_changes($id);$assert(count($merged)===1 && $merged[0]['new_value'][0]['benefits'][1]['text']==='Meer ruimte voor initiatief','Inline edits merge into an existing parent repeater draft');
 $published=PKCA_Content::publish($id);if(is_wp_error($published))throw new RuntimeException($published->get_error_message());
 $saved=PKCA_Content::saved_rows(PKCA_Content::target($id))[0]['flex_content'][0]['cards'];
 $assert($saved[0]['benefits'][0]['text']==='Echte persoonlijke aandacht' && $saved[0]['background']['ID']===$images[1],'Nested text and background image publish correctly');
 $assert($saved[0]['benefits'][1]['text']==='Meer ruimte voor initiatief' && $saved[0]['title']===$rows[0]['title'],'Unchanged nested values remain exact');
 $saved[0]['background']=false;
 PKCA_Content::add_changes($id,[['section'=>1,'field_path'=>['cards'],'type'=>'repeater','value'=>$saved]]);
 $cleared=PKCA_Content::publish($id);if(is_wp_error($cleared))throw new RuntimeException($cleared->get_error_message());
 $assert(!PKCA_Content::saved_rows(PKCA_Content::target($id))[0]['flex_content'][0]['cards'][0]['background'],'Optional nested background can be removed');
 PKCA_Content::add_changes($id,[['section'=>1,'field_path'=>['cards','0','title'],'type'=>'text','value'=>'Nieuwe titel']]);
 $current=PKCA_Content::inspect($id)['sections'][0]['fields'][0]['value'];$current[0]['benefits'][0]['text']='Nieuwe plus';
 PKCA_Content::add_changes($id,[['section'=>1,'field_path'=>['cards'],'type'=>'repeater','value'=>$current]]);
 $draft=PKCA_Content::get_changes($id);$assert(count($draft)===1 && $draft[0]['new_value'][0]['title']==='Nieuwe titel' && $draft[0]['old_value'][0]['title']==='Samen verder','Parent edits absorb earlier child edits and preserve the real original for undo');

} finally {
 if($id){PKCA_Content::discard($id);wp_delete_post($id,true);}foreach($images as $image)wp_delete_attachment($image,true);acf_remove_local_field_group('group_pkca_nested_test');wp_set_current_user($user);
}
