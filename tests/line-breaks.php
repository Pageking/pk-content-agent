<?php
/** Read-only runtime test; registers temporary ACF field definitions in memory. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) exit;
$assert = static function( bool $ok, string $message ): void { if ( ! $ok ) throw new RuntimeException($message); echo "PASS: $message\n"; };
$key = 'field_pkca_line_breaks_test';
$definition = array('key'=>$key,'name'=>'subtitle','label'=>'Subtitel','type'=>'textarea','new_lines'=>'br');
acf_add_local_field($definition);
try {
 $raw = "Zoveel expertise.\n\nZo dichtbij.";
 $change = array('type'=>'text','field_key'=>$key,'field_path'=>array('subtitle'),'row_index'=>0,'layout_index'=>0,'old_value'=>'Zoveel expertise. Zo dichtbij.','new_value'=>$raw);
 $decorate = new ReflectionMethod(PKCA_Content::class,'decorate_change');
 $decorated = $decorate->invoke(null,$change);
 $assert($decorated['new_value']===$raw && substr_count($decorated['new_preview_html'],'<br')===2,'Preview formats Enter and blank lines without changing the raw saved value');
 $rows = array(array('flex_content'=>array(array('subtitle'=>'Zoveel expertise. Zo dichtbij.'))));
 $preview = PKCA_Content::apply_change_to_value($rows,$change);
 $assert($preview[0]['flex_content'][0]['subtitle']===nl2br($raw),'Full server-rendered preview uses the same ACF line break formatting');
 $saved = PKCA_Content::apply_change_to_value($rows,$change,false);
 $assert($saved[0]['flex_content'][0]['subtitle']===$raw,'Storage path retains plain newline characters');
 $fields = array();$schema = new ReflectionMethod(PKCA_Content::class,'apply_field_schema');
 $schema->invokeArgs(null,array(array($definition),array('subtitle'=>nl2br($raw)),array(),&$fields,array()));
 $assert($fields[0]['value']===$raw,'Reading formatted ACF content preserves consecutive blank lines');
 $format = new ReflectionMethod(PKCA_Content::class,'format_text_preview');
 $assert($format->invoke(null,$raw,array('type'=>'textarea','new_lines'=>''))===$raw,'Textarea fields without newline formatting remain unchanged');
 $assert(str_contains($format->invoke(null,"First\n\nSecond",array('type'=>'textarea','new_lines'=>'wpautop')),'</p>'),'Paragraph-formatting textarea fields retain their ACF formatting');
} finally { acf_remove_local_field($key); }
