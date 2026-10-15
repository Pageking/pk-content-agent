<?php
/** Local integration test. Temporary attachment records and draft are removed; no files created. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) exit;
$assert = static function( bool $ok, string $message ): void { if ( ! $ok ) throw new RuntimeException($message); echo "PASS: $message\n"; };
$user=get_current_user_id();
wp_set_current_user((int)get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID'))[0]);
$attachments=array();$post=0;
$filter=static fn()=>null;
add_filter('pre_option_pkca_allowed_roles',$filter);
try {
 $post=wp_insert_post(array('post_type'=>'page','post_status'=>'draft','post_title'=>'PKCA media fixture'));
 $prefix='PKCA-media-'.wp_generate_uuid4();
 for($i=0;$i<25;$i++){
  $file=$prefix.'-'.$i.'.jpg';
  $id=wp_insert_attachment(array('post_title'=>$prefix.' '.$i,'post_mime_type'=>'image/jpeg','post_status'=>'inherit','post_parent'=>$post),false,$post);
  $attachments[]=$id;update_post_meta($id,'_wp_attached_file',$file);
 }
 $request=new WP_REST_Request('GET','/pk-content-agent/v1/media');$request->set_param('post_id',$post);$request->set_param('search',$prefix);
 $response=rest_do_request($request);$data=$response->get_data();
 $assert($response->get_status()===200 && $data['total']===25 && count($data['items'])===24 && $data['pages']===2,'Media API searches images and returns a bounded first page');
 $assert($data['items'][0]['id']===end($attachments) && isset($data['items'][0]['url'],$data['items'][0]['title']),'Media items include newest-first IDs, thumbnails and labels');
 $request->set_param('page',2);$response=rest_do_request($request);$data=$response->get_data();
 $assert(count($data['items'])===1 && $data['items'][0]['id']===$attachments[0],'Second page returns the remaining image without duplicates');
 $request->set_param('search',$prefix.'-no-match');$request->set_param('page',1);
 $assert(rest_do_request($request)->get_data()['items']===array(),'Unmatched search returns a clear empty result');
 $query=$GLOBALS['wp_query'];$GLOBALS['wp_query']=new WP_Query(array('page_id'=>$post,'post_status'=>'draft'));
 $before=wp_script_is('media-views','enqueued');PKCA_Plugin::instance()->enqueue_frontend();
 $assert(wp_script_is('pk-content-agent','enqueued') && wp_script_is('media-views','enqueued')===$before,'Frontend agent no longer adds the heavyweight eval-based WordPress media stack');
 $GLOBALS['wp_query']=$query;
 wp_set_current_user(0);$error=(new PKCA_REST())->media($request);
 $assert(is_wp_error($error) && $error->get_error_code()==='pkca_media_forbidden','Media browsing requires WordPress upload permissions');
} finally {
 remove_filter('pre_option_pkca_allowed_roles',$filter);wp_set_current_user($user);
 foreach($attachments as $id)wp_delete_attachment($id,true);
 if($post)wp_delete_post($post,true);
}
