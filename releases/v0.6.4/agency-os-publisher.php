<?php
/**
 * Plugin Name: Agency OS Approved Publisher
 * Description: Applies explicitly approved website changes and verifies their saved values.
 * Version: 0.6.4
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI: https://github.com/Bholmstock/agency-os-publisher-releases
 */
if (!defined('ABSPATH')) exit;
// 0.5.0 — a WordPress site is where content, permissions and URL conflicts
// are truly known, so publishing preflight lives here too: it runs the same
// checks /apply would, without writing anything, and names which one failed
// instead of one generic refusal. AOS_MANIFEST_URL and the Update URI header
// above point at the public release-only repository
// github.com/Bholmstock/agency-os-publisher-releases; override
// AOS_MANIFEST_URL in wp-config.php only if that repository moves.
if(!defined('AOS_MANIFEST_URL'))define('AOS_MANIFEST_URL','https://raw.githubusercontent.com/Bholmstock/agency-os-publisher-releases/main/manifest.json');
define('AOS_PLUGIN_VERSION','0.6.4');
// 0.5.2 — category intelligence is a new, truthfully-advertised capability
// (see /capabilities and /categories below). It is genuinely additive, not
// merely additive-by-intent, because it is gated by an explicit request-side
// marker rather than by this plugin's own version: a new_post change is only
// held to the category contract when it carries
// 'categoryContractVersion'=>AOS_CATEGORY_CONTRACT_VERSION (see
// aos_category_contract_state() below). A request that OMITS the marker
// entirely — a genuine 0.5.1-era Agency OS, or any client that has not
// reviewed a category selection — publishes exactly as 0.5.1 always did: no
// category checks, no category assignment, WordPress's own default category
// applies. Only a request that both declares the marker AND was prepared by
// an Agency OS that first confirmed this site advertises category support
// (see /capabilities) is required to carry a valid, reviewed primary
// category. A request that carries the marker with a value this plugin does
// NOT understand (anything other than the exact integer
// AOS_CATEGORY_CONTRACT_VERSION) is a THIRD, distinct case: it is never
// treated as "absent" and never allowed to bypass category enforcement —
// see aos_category_contract_state() — it is refused outright with an
// explicit category_contract check, before any post is created. This is
// also why the protocol version below can safely stay unchanged: nothing
// about the existing wire contract is different for a caller that never
// opts into the newer one, in either version direction — a 0.5.2 Agency OS
// talking to a 0.5.1 site degrades the same way (it never learns this site
// supports categories, so it never sends the marker either).
define('AOS_PROTOCOL_VERSION',3);
// 0.5.2 — the category contract's own version, independent of the plugin
// version. See aos_category_contract_state() for how a new_post change's
// marker (absent / valid / invalid) is decided against this constant.
define('AOS_CATEGORY_CONTRACT_VERSION',1);
// 0.5.3 — image sub-size generation is intentionally deferred until after
// the approved article has been saved, published, and verified. Some managed
// hosts terminate long REST requests while WordPress/Divi creates every
// registered thumbnail size for multiple large PNGs. The original approved
// files are still uploaded and hash-verified synchronously; only derivative
// thumbnails move to a one-shot WP-Cron job. The article therefore never
// depends on background work, while the Media Library can fill in its normal
// responsive sizes shortly after publication without holding /apply open.
function aos_generate_attachment_metadata_deferred($attachmentId){
 $attachmentId=(int)$attachmentId;$file=$attachmentId?get_attached_file($attachmentId):false;
 if(!$file||!file_exists($file))return;
 require_once ABSPATH.'wp-admin/includes/image.php';
 $metadata=wp_generate_attachment_metadata($attachmentId,$file);
 if(!is_wp_error($metadata)&&is_array($metadata))wp_update_attachment_metadata($attachmentId,$metadata);
}
add_action('aos_generate_attachment_metadata','aos_generate_attachment_metadata_deferred',10,1);
function aos_schedule_attachment_metadata($media){
 foreach((array)$media as $m){$attachmentId=(int)($m['attachmentId']??0);if(!$attachmentId)continue;
  $args=array($attachmentId);
  if(!wp_next_scheduled('aos_generate_attachment_metadata',$args))wp_schedule_single_event(time()+5,'aos_generate_attachment_metadata',$args);
 }
}
function aos_local_url($url){return strtolower(wp_parse_url($url,PHP_URL_HOST))===strtolower(wp_parse_url(home_url(),PHP_URL_HOST))&&wp_parse_url($url,PHP_URL_SCHEME)==='https';}
// 0.5.7 — read-only official Rank Math score. Rank Math stores its score in post meta once it has
// analysed the post (normally when the post is opened in the WordPress editor). Until then there is no
// score, and this reports "pending". It never changes the post, never triggers an analysis and never
// reveals a post the caller cannot edit.
function aos_rank_math_score_value($id){if(!defined('RANK_MATH_VERSION'))return null;$raw=get_post_meta($id,'rank_math_seo_score',true);if($raw===''||!is_numeric($raw))return null;$score=(int)$raw;return $score>=0&&$score<=100?$score:null;}
function aos_rank_math_score($r){$url=(string)($r['url']??'');if(!aos_local_url($url))return new WP_Error('site','Choose a page on this website',array('status'=>400));$id=url_to_postid($url);if(!$id)return new WP_Error('missing','No WordPress post exists at this address',array('status'=>404));if(!current_user_can('edit_post',$id))return new WP_Error('access','This page cannot be read',array('status'=>403));$score=aos_rank_math_score_value($id);return array('postId'=>(int)$id,'url'=>get_permalink($id),'rankMath'=>defined('RANK_MATH_VERSION'),'score'=>$score,'status'=>$score===null?'pending':'recorded','checkedAt'=>gmdate('c'));}
function aos_state($id){$p=get_post($id);return array('content'=>$p->post_content,'title'=>get_post_meta($id,'rank_math_title',true),'description'=>get_post_meta($id,'rank_math_description',true),'schema'=>get_post_meta($id,'_aos_schema',true),'featured'=>(int)get_post_thumbnail_id($id),'byline'=>(string)get_post_meta($id,'_aos_byline',true),'author'=>(int)$p->post_author,'commentStatus'=>(string)$p->comment_status,'pingStatus'=>(string)$p->ping_status,'layout'=>aos_layout_value($id),'focusKeyword'=>(string)get_post_meta($id,'rank_math_focus_keyword',true));}
// 0.3.0 — how this site can present an article without a sidebar. Divi keeps
// the choice in post meta; other themes may offer a post template whose name
// says full width or no sidebar. When neither exists the publisher says so
// rather than claiming a layout it cannot set.
function aos_layout_mechanism(){
 $theme=wp_get_theme();$names=array_map('strtolower',array_filter(array($theme->get('Name'),$theme->get('Template'),get_template(),get_stylesheet())));
 if(defined('ET_BUILDER_VERSION')||function_exists('et_setup_theme')||in_array('divi',$names,true))return array('mechanism'=>'divi','value'=>'et_full_width_page','label'=>'Divi page layout: full width');
 $templates=$theme->get_page_templates(null,'post');
 if(is_array($templates))foreach($templates as $file=>$name){if(preg_match('/full[-_ ]?width|no[-_ ]?sidebar|sidebar[-_ ]?less/i',$name.' '.$file))return array('mechanism'=>'template','value'=>$file,'label'=>'Post template: '.$name);}
 return array('mechanism'=>null,'value'=>'','label'=>'');
}
// The active runtime, not a public CSS class, determines the native adapter.
// A Divi child theme keeps its own identity; its parent selects the builder.
function aos_native_page_capability(){
 $theme=wp_get_theme();
 $identity=array('name'=>(string)$theme->get('Name'),'template'=>(string)get_template(),'stylesheet'=>(string)get_stylesheet());
 $diviTheme=strtolower($identity['template'])==='divi'||strtolower($identity['stylesheet'])==='divi';
 // Divi 4 registers modules on `wp`, which REST dispatch does not fire.
 // Confirm the actual loaded runtime, not only the theme label. Registration
 // is performed only while rendering the authorized owned draft below.
 $diviActive=defined('ET_BUILDER_VERSION')&&function_exists('shortcode_exists')&&(shortcode_exists('et_pb_section')||(class_exists('ET_Builder_Element')&&class_exists('ET_Builder_Module_Shortcode_Manager')&&function_exists('et_builder_init_global_settings')&&function_exists('et_builder_add_main_elements')));
 $builder=$diviTheme||$diviActive?'divi':(defined('ELEMENTOR_VERSION')?'elementor':'unknown');
 $version=$builder==='divi'&&defined('ET_BUILDER_VERSION')?(string)ET_BUILDER_VERSION:($builder==='elementor'?(string)ELEMENTOR_VERSION:'');
 // The validator is installed with the adapter; detection alone cannot claim
 // that a page can be safely written, rendered, or edited in that builder.
 $supported=$builder==='divi'&&$diviActive&&preg_match('/^4\.\d+\.\d+(?:[-+][a-z0-9.-]+)?$/i',$version)&&function_exists('aos_safe_divi_page')&&function_exists('aos_native_page_checks');
 return array('contractVersion'=>1,'builder'=>$builder,'builderVersion'=>$version,'theme'=>$identity,'supported'=>(bool)$supported,'contentFormats'=>$supported?array('divi_shortcodes'):array());
}

function aos_layout_value($id){$m=aos_layout_mechanism();if($m['mechanism']==='divi')return (string)get_post_meta($id,'_et_pb_page_layout',true);if($m['mechanism']==='template')return (string)get_page_template_slug($id);return '';}
// Eligible authors: users who can write posts here. Names and roles only.
function aos_article_byline($name){
 $post=get_post();if(!$post||is_admin())return $name;
 $byline=(string)get_post_meta($post->ID,'_aos_byline',true);return $byline!==''?$byline:$name;
}
add_filter('the_author','aos_article_byline');
add_filter('get_the_author_display_name','aos_article_byline');
add_filter('rank_math/json_ld',function($data){
 $post=get_post();$byline=$post?(string)get_post_meta($post->ID,'_aos_byline',true):'';
 if($byline!==''&&is_array($data))foreach($data as &$node){if(is_array($node)&&isset($node['@type'])&&preg_match('/Article|BlogPosting/',implode(' ',(array)$node['@type'])))$node['author']=array('@type'=>'Person','name'=>$byline);}unset($node);return $data;
},99);
function aos_authors(){
 $users=function_exists('wp_roles')?get_users(array('capability'=>'edit_posts','number'=>100,'orderby'=>'display_name','order'=>'ASC')):array();
 if(!count($users))$users=get_users(array('role__in'=>array('administrator','editor','author'),'number'=>100,'orderby'=>'display_name','order'=>'ASC'));
 $roles=wp_roles()->role_names;$out=array();
 foreach($users as $u){if(!user_can($u->ID,'edit_posts'))continue;$role=isset($u->roles[0])&&isset($roles[$u->roles[0]])?translate_user_role($roles[$u->roles[0]]):'';$out[]=array('id'=>(int)$u->ID,'name'=>(string)$u->display_name,'role'=>$role);}
 return array('default'=>get_current_user_id(),'canAssign'=>current_user_can('edit_others_posts'),'users'=>$out);
}
// 0.5.2 — every category this site has, read-only. Stable ids, names, slugs,
// parents and counts, plus the site's own default (usually "Uncategorized")
// so Agency OS never recommends the unchanged fallback as if it were a real
// choice. Category creation needs manage_categories; this function only ever
// reads.
function aos_categories(){
 $terms=get_categories(array('hide_empty'=>false,'number'=>300));
 $out=array();
 foreach($terms as $t)$out[]=array('id'=>(int)$t->term_id,'name'=>(string)$t->name,'slug'=>(string)$t->slug,'parent'=>(int)$t->parent,'count'=>(int)$t->count);
 return array('default'=>(int)get_option('default_category'),'canCreate'=>current_user_can('manage_categories'),'categories'=>$out);
}
// 0.5.2 — the same checks aos_new_post_checks() folds in, split out for
// clarity: a primary category is required, a resolvable id or an explicitly
// approved new name/slug with no conflict; a secondary category, if any,
// follows the same rule and must differ from the primary; creating any new
// category needs manage_categories. Nothing here writes to the site.
function aos_category_checks($cat){
 $checks=array();
 $categories=aos_categories();$slugs=array();$ids=array();
 foreach($categories['categories'] as $t){$slugs[]=strtolower($t['slug']);$ids[(int)$t['id']]=true;}
 $resolve=function($sel)use($ids,$slugs){
  if(!is_array($sel))return array(false,'');
  if(($sel['source']??'')==='existing')return array(isset($ids[(int)($sel['id']??0)]),'');
  if(($sel['source']??'')==='new'){
   $name=trim((string)($sel['name']??''));$slug=strtolower((string)($sel['slug']??''));$approved=!empty($sel['approved']);
   if($name===''||!preg_match('/^[-a-z0-9]+$/',$slug))return array(false,'');
   if(in_array($slug,$slugs,true))return array(false,'A category with this slug already exists. Choose the existing category instead.');
   if(!$approved)return array(false,'Approve creating this category before publishing.');
   return array(true,'');
  }
  return array(false,'');
 };
 $primary=is_array($cat)?($cat['primary']??null):null;$secondary=is_array($cat)?($cat['secondary']??null):null;
 list($primaryOk,$primaryMsg)=$resolve($primary);
 $checks[]=array('code'=>'category_primary','ok'=>is_array($primary)&&$primaryOk,'message'=>is_array($primary)?($primaryOk?'':($primaryMsg?:'Choose a valid primary category.')):'A primary category is required.');
 if($secondary!==null){list($secondaryOk,$secondaryMsg)=$resolve($secondary);$checks[]=array('code'=>'category_secondary','ok'=>$secondaryOk,'message'=>$secondaryOk?'':($secondaryMsg?:'Choose a valid additional category.'));}
 $creating=(is_array($primary)&&($primary['source']??'')==='new')||(is_array($secondary)&&($secondary['source']??'')==='new');
 $permission=!$creating||current_user_can('manage_categories');
 $checks[]=array('code'=>'category_permission','ok'=>$permission,'message'=>$permission?'':'Creating a new category requires category-management permission on this site.');
 $duplicateExisting=is_array($primary)&&is_array($secondary)&&($primary['source']??'')==='existing'&&($secondary['source']??'')==='existing'&&(int)($primary['id']??0)===(int)($secondary['id']??-1);
 $duplicateNew=is_array($primary)&&is_array($secondary)&&($primary['source']??'')==='new'&&($secondary['source']??'')==='new'&&strtolower((string)($primary['slug']??''))===strtolower((string)($secondary['slug']??'\0'));
 $checks[]=array('code'=>'category_duplicate','ok'=>!($duplicateExisting||$duplicateNew),'message'=>($duplicateExisting||$duplicateNew)?'The primary and additional category must be different.':'');
 return $checks;
}
function aos_snapshot($url){
 if(!aos_local_url($url))return new WP_Error('site','Choose a page on this website',array('status'=>400));$id=url_to_postid($url);
 if(!$id)return array('url'=>$url,'exists'=>false,'content'=>'','title'=>'','description'=>'','beforeHash'=>hash('sha256','absent:'.$url));
 if(!current_user_can('edit_post',$id))return new WP_Error('access','This page cannot be edited',array('status'=>403));$s=aos_state($id);return array_merge($s,array('url'=>get_permalink($id),'exists'=>true,'postId'=>$id,'pageTitle'=>get_the_title($id),'beforeHash'=>hash('sha256',wp_json_encode($s))));
}
// 0.5.2 — the categories a job actually wrote, read back from the post
// itself (never from what apply merely believes it did), each named with
// whether it is still assigned right now, plus the Rank Math primary-category
// meta when Rank Math is present. Read-only.
function aos_category_receipt($id,$catJob){
 if(!is_array($catJob))return null;
 $assignedIds=wp_get_post_categories($id);
 // 0.5.2.2 — wp_get_post_categories() can return a WP_Error or some other
 // non-array value (a site-level failure reading terms back). Never pass
 // either into in_array() below: treat it as "nothing confirmed assigned"
 // so this read-only receipt reports honestly unverified instead of fatally
 // erroring.
 if(is_wp_error($assignedIds)||!is_array($assignedIds))$assignedIds=array();
 $describe=function($tid)use($assignedIds){
  if(!$tid)return null;$term=get_term($tid,'category');
  return array('id'=>(int)$tid,'name'=>($term&&!is_wp_error($term))?(string)$term->name:'','verified'=>in_array((int)$tid,$assignedIds,true));
 };
 $primary=$describe($catJob['primaryId']??0);
 $secondary=($catJob['secondaryId']??null)?$describe($catJob['secondaryId']):null;
 $rankMathPrimary=defined('RANK_MATH_VERSION')?(int)get_post_meta($id,'rank_math_primary_category',true):0;
 return array('primary'=>$primary,'secondary'=>$secondary,'created'=>$catJob['created']??array(),'rankMath'=>array('supported'=>defined('RANK_MATH_VERSION'),'verified'=>defined('RANK_MATH_VERSION')&&$rankMathPrimary===(int)($catJob['primaryId']??0)));
}
function aos_categories_verified($id,$catJob){
 $info=aos_category_receipt($id,$catJob);
 if(!$info||!$info['primary'])return false;
 return $info['primary']['verified']&&($info['secondary']===null||$info['secondary']['verified']);
}
function aos_receipt($j){
 $id=$j['postId'];if(!$id){$ids=get_posts(array('post_type'=>array('post','page'),'post_status'=>'any','meta_key'=>'_aos_job','meta_value'=>$j['jobId'],'fields'=>'ids'));$id=count($ids)===1?$ids[0]:0;}
 if(!$id)return array('jobId'=>$j['jobId'],'proposalHash'=>$j['proposalHash'],'verified'=>false,'url'=>$j['url'],'postId'=>0);
 if(!current_user_can('edit_post',$id))return new WP_Error('access','Not permitted',array('status'=>403));
 $s=aos_state($id);$ok=is_array($j['expected'])&&count($j['expected'])>0;foreach(($j['expected']??array()) as $k=>$v)$ok=$ok&&array_key_exists($k,$s)&&$s[$k]===$v;foreach(($j['media']??array()) as $m){$file=function_exists('wp_get_original_image_path')?wp_get_original_image_path($m['attachmentId']):get_attached_file($m['attachmentId']);$ok=$ok&&$file&&file_exists($file)&&hash_file('sha256',$file)===$m['sha256']&&get_post_meta($m['attachmentId'],'_wp_attachment_image_alt',true)===$m['alt'];}if(isset($j['newTitle']))$ok=$ok&&get_post($id)->post_title===$j['newTitle']&&get_post_status($id)==='publish';
 // 0.5.2 — an approved new post that named categories is verified only once
 // WordPress confirms them actually assigned, exactly like every other
 // expected field above; Rank Math's own primary-category meta is reported
 // honestly but never required for $ok, since not every site runs Rank Math.
 $categories=isset($j['categories'])?aos_category_receipt($id,$j['categories']):null;
 if($categories!==null)$ok=$ok&&aos_categories_verified($id,$j['categories']);
 // 0.3.0 — what the website can vouch for, each named, and a layout that
 // could not be set said so rather than folded into "verified".
 $content=get_post($id)->post_content;$supporting=array();foreach(($j['media']??array()) as $m){if(($m['role']??'')==='featured')continue;$src=wp_get_attachment_url($m['attachmentId']);$supporting[]=array('attachmentId'=>(int)$m['attachmentId'],'inserted'=>$src&&strpos($content,$src)!==false);}
 $mech=$j['layoutMechanism']??null;$layout=array('requested'=>$j['layoutRequested']??'','mechanism'=>$mech,'value'=>$s['layout'],'verified'=>($j['layoutRequested']??'')!=='full_width'?null:($mech?$s['layout']===$j['expected']['layout']:false),'note'=>($j['layoutRequested']??'')==='full_width'&&!$mech?'Full-width layout could not be verified for this site.':'');
 // Native editability is read back from the saved page, including on retries.
 $native=null;if(isset($j['nativePage'])){$native=$j['nativePage'];$native['editable']=get_post_type($id)==='page'&&get_post_meta($id,'_et_pb_use_builder',true)==='on'&&get_post_meta($id,'_et_pb_built_for_post_type',true)==='page';$native['verified']=$native['editable']&&$s['layout']==='et_full_width_page';$ok=$ok&&$native['verified'];}
 return array('jobId'=>$j['jobId'],'proposalHash'=>$j['proposalHash'],'url'=>get_permalink($id),'nativePage'=>$native,'verified'=>$ok,'postId'=>(int)$id,'postType'=>get_post_type($id),'verifiedAt'=>gmdate('c'),'author'=>(int)$s['author'],'authorName'=>!empty($s['byline'])?$s['byline']:(string)get_the_author_meta('display_name',$s['author']),'commentStatus'=>$s['commentStatus'],'pingStatus'=>$s['pingStatus'],'featuredMediaId'=>(int)$s['featured'],'supportingImages'=>$supporting,'seo'=>array('title'=>$s['title']!=='','description'=>$s['description']!=='','focusKeyword'=>$s['focusKeyword']!=='','rankMath'=>defined('RANK_MATH_VERSION')),'layout'=>$layout,'categories'=>$categories);
}
// 0.5.2.3 — a job whose $rollback could not fully remove its post (see
// aos_apply()'s $rollback closure) is never allowed to fall through to a
// normal aos_receipt() replay, which could misreport a still-public,
// unresolved article as an ordinary (even verified) receipt. This is the
// dedicated, honest status for exactly that case, called every time such a
// job is looked up again — it re-reads the post's ACTUAL current status
// fresh each time (never the stale status recorded when cleanup failed),
// since an operator may have intervened manually since. A WP_Error is
// returned either way, never a receipt shape, so this can never be mistaken
// for a completed job.
function aos_cleanup_status($j){
 $postId=(int)($j['cleanup']['postId']??0);
 $status=$postId?get_post_status($postId):false;
 if(!$postId||$status===false)return new WP_Error('cleanup_resolved','A previous attempt for this job required manual cleanup and the post no longer exists on this site. If this was resolved manually, submit a new publishing request rather than retrying this exact job.',array('status'=>409,'jobId'=>$j['jobId']));
 if($status==='publish')return new WP_Error('cleanup_required_critical','A previous attempt for this job left an article publicly visible that could not be safely removed. It may still be public and requires immediate manual action: review and unpublish or delete post ID '.$postId.' on this site.',array('status'=>500,'jobId'=>$j['jobId'],'postId'=>$postId));
 return new WP_Error('cleanup_required','A previous attempt for this job left a draft that could not be fully removed automatically. It has been verified non-public (post ID '.$postId.'), but needs manual review before retrying this job.',array('status'=>409,'jobId'=>$j['jobId'],'postId'=>$postId));
}
// WordPress rewrites entities as it filters (wp_kses_normalize_entities pads
// &#39; to &#039;), so safety is judged against that normalised form, and the
// normalised form is what gets published. Any tag or attribute wp_kses_post
// would remove still fails: only entity spelling is forgiven.
function aos_normalized_html($html){return is_string($html)?wp_kses_normalize_entities($html):'';}
function aos_safe_html($html){if(!is_string($html)||trim($html)===''||strlen($html)>400000)return false;$n=aos_normalized_html($html);return wp_kses_post($n)===$n;}
// 0.5.0 — every reason a new publication cannot proceed, named and checked
// independently rather than folded into one message. /apply uses the first
// failure to refuse the write; /preflight reports all of them so a person can
// fix everything at once, before Agency OS offers final approval.
// Native Divi layouts are separately validated; KSES alone does not reject
// executable or third-party shortcodes embedded in otherwise plain text.
function aos_safe_divi_page($content){
 if(!is_string($content)||trim($content)===''||strlen($content)>100000||!aos_safe_html($content))return false;
 preg_match_all('/<[^>]*>/',$content,$htmlTags);foreach($htmlTags[0] as $tag)if(preg_match('/<\s*(?:script|iframe|form|style|object|embed)\b|\s+on[a-z]+\s*=|javascript\s*:/i',$tag))return false;
 $schema=json_decode(<<<'AOS_DIVI_SCHEMA'
{"modules":{"et_pb_section":["et_pb_row"],"et_pb_row":["et_pb_column"],"et_pb_column":["et_pb_text","et_pb_image","et_pb_button","et_pb_blurb","et_pb_divider"],"et_pb_text":[],"et_pb_image":[],"et_pb_button":[],"et_pb_blurb":[],"et_pb_divider":[]},"attributes":{"et_pb_section":["_builder_version","_module_preset","global_colors_info","admin_label","fb_built","background_color","background_image","background_size","background_position","background_repeat","use_background_color_gradient","background_color_gradient_stops","background_color_gradient_direction","background_color_gradient_overlays_image","top_divider_style","top_divider_color","top_divider_height","top_divider_repeat","top_divider_flip","bottom_divider_style","bottom_divider_color","bottom_divider_height","bottom_divider_repeat","bottom_divider_flip","custom_padding","custom_padding_tablet","custom_padding_phone","custom_padding_last_edited","custom_margin","custom_margin_tablet","custom_margin_phone","custom_margin_last_edited","border_radii","border_width_all","border_color_all","border_width_top","border_color_top","border_width_right","border_color_right","border_width_bottom","border_color_bottom","border_width_left","border_color_left"],"et_pb_row":["_builder_version","_module_preset","global_colors_info","admin_label","column_structure","use_custom_gutter","gutter_width","make_equal","width","max_width","module_alignment","custom_padding","custom_padding_tablet","custom_padding_phone","custom_padding_last_edited","custom_margin","custom_margin_tablet","custom_margin_phone","custom_margin_last_edited"],"et_pb_column":["_builder_version","_module_preset","global_colors_info","admin_label","type","custom_padding","custom_padding_tablet","custom_padding_phone","custom_padding_last_edited","custom_margin","custom_margin_tablet","custom_margin_phone","custom_margin_last_edited","background_color","background_image","background_size","background_position","background_repeat","use_background_color_gradient","background_color_gradient_stops","background_color_gradient_direction","background_color_gradient_overlays_image","border_radii","border_width_all","border_color_all","border_width_top","border_color_top","border_width_right","border_color_right","border_width_bottom","border_color_bottom","border_width_left","border_color_left"],"et_pb_text":["_builder_version","_module_preset","global_colors_info","admin_label","text_font","text_text_color","text_font_size","text_font_size_tablet","text_font_size_phone","text_font_size_last_edited","text_line_height","header_font","header_text_color","header_font_size","header_font_size_tablet","header_font_size_phone","header_font_size_last_edited","header_line_height","header_2_font","header_2_text_color","header_2_font_size","header_2_font_size_tablet","header_2_font_size_phone","header_2_font_size_last_edited","header_2_line_height","header_3_font","header_3_text_color","header_3_font_size","header_3_font_size_tablet","header_3_font_size_phone","header_3_font_size_last_edited","header_3_line_height","link_font","link_text_color","link_font_size","link_font_size_tablet","link_font_size_phone","link_font_size_last_edited","link_line_height","text_orientation","text_orientation_tablet","text_orientation_phone","text_orientation_last_edited","background_layout","max_width","custom_padding","custom_padding_tablet","custom_padding_phone","custom_padding_last_edited","custom_margin","custom_margin_tablet","custom_margin_phone","custom_margin_last_edited","background_color","background_image","background_size","background_position","background_repeat","use_background_color_gradient","background_color_gradient_stops","background_color_gradient_direction","background_color_gradient_overlays_image","border_radii","border_width_all","border_color_all","border_width_top","border_color_top","border_width_right","border_color_right","border_width_bottom","border_color_bottom","border_width_left","border_color_left"],"et_pb_image":["_builder_version","_module_preset","global_colors_info","admin_label","src","alt","title_text","url","url_new_window","align","force_fullwidth","disabled_on","custom_padding","custom_padding_tablet","custom_padding_phone","custom_padding_last_edited","custom_margin","custom_margin_tablet","custom_margin_phone","custom_margin_last_edited","border_radii","border_width_all","border_color_all","border_width_top","border_color_top","border_width_right","border_color_right","border_width_bottom","border_color_bottom","border_width_left","border_color_left"],"et_pb_button":["_builder_version","_module_preset","global_colors_info","admin_label","button_url","url_new_window","button_text","custom_button","button_text_size","button_text_size_tablet","button_text_size_phone","button_text_size_last_edited","button_text_color","button_bg_color","button_border_width","button_border_color","button_border_radius","button_font","button_use_icon","button_icon","button_icon_color","button_icon_placement","button_on_hover","button_alignment","button_bg_color__hover","button_bg_color__hover_enabled","button_border_color__hover","button_border_color__hover_enabled","button_text_color__hover","button_text_color__hover_enabled","custom_padding","custom_padding_tablet","custom_padding_phone","custom_padding_last_edited","custom_margin","custom_margin_tablet","custom_margin_phone","custom_margin_last_edited"],"et_pb_blurb":["_builder_version","_module_preset","global_colors_info","admin_label","title","use_icon","font_icon","icon_color","icon_placement","icon_placement_tablet","icon_placement_phone","icon_placement_last_edited","use_icon_font_size","icon_font_size","icon_font_size_tablet","icon_font_size_phone","icon_font_size_last_edited","image_icon_width","header_font","header_text_color","header_font_size","header_font_size_tablet","header_font_size_phone","header_font_size_last_edited","header_line_height","body_font","body_text_color","body_font_size","body_font_size_tablet","body_font_size_phone","body_font_size_last_edited","body_line_height","header_level","text_orientation","text_orientation_tablet","text_orientation_phone","text_orientation_last_edited","background_layout","animation","custom_padding","custom_padding_tablet","custom_padding_phone","custom_padding_last_edited","custom_margin","custom_margin_tablet","custom_margin_phone","custom_margin_last_edited","border_radii","border_width_all","border_color_all","border_width_top","border_color_top","border_width_right","border_color_right","border_width_bottom","border_color_bottom","border_width_left","border_color_left"],"et_pb_divider":["_builder_version","_module_preset","global_colors_info","admin_label","color","show_divider","divider_style","divider_weight","max_width","module_alignment","custom_padding","custom_padding_tablet","custom_padding_phone","custom_padding_last_edited","custom_margin","custom_margin_tablet","custom_margin_phone","custom_margin_last_edited"]},"urls":["src","url","button_url","background_image"],"text":["admin_label","title","title_text","alt","button_text"],"exact":["_builder_version","module_id","module_class","type","fullwidth","specialty","background_layout","background_color","background_enable_image","background_size","background_position","background_repeat","background_blend","background_enable_color","use_background_color_gradient","background_color_gradient_start","background_color_gradient_end","background_color_gradient_type","background_color_gradient_direction","background_color_gradient_start_position","background_color_gradient_end_position","background_color_gradient_overlays_image","background_color_gradient_stops","use_custom_gutter","gutter_width","equalize_column_heights","make_equal","column_structure","custom_width","use_custom_width","width","max_width","min_width","height","max_height","min_height","horizontal_offset","vertical_offset","position","position_origin","overflow_x","overflow_y","z_index","text_orientation","text_text_color","text_font","text_font_size","text_line_height","header_font","header_font_size","header_text_color","header_line_height","header_2_font","header_2_font_size","header_2_text_color","header_2_line_height","body_font","body_font_size","body_text_color","body_line_height","custom_padding","custom_margin","custom_button","button_alignment","button_font","button_text_size","button_text_color","button_bg_color","button_border_color","button_border_width","button_border_radius","button_use_icon","button_icon","button_icon_color","button_icon_placement","button_on_hover","url_new_window","show_in_lightbox","align","force_fullwidth","animation_style","use_icon","font_icon","icon_color","icon_placement","use_circle","circle_color","use_circle_border","circle_border_color","use_icon_font_size","icon_font_size","image_max_width","image_icon_width","show_divider","color","divider_style","divider_weight","border_radii","border_width_all","border_color_all","border_style_all","box_shadow_style","top_divider_style","top_divider_color","top_divider_height","top_divider_flip","top_divider_arrangement","bottom_divider_style","bottom_divider_color","bottom_divider_height","bottom_divider_flip","bottom_divider_arrangement","_module_preset","global_colors_info","admin_label","fb_built","background_image","top_divider_repeat","bottom_divider_repeat","border_width_top","border_color_top","border_width_right","border_color_right","border_width_bottom","border_color_bottom","border_width_left","border_color_left","module_alignment","header_3_font","header_3_text_color","header_3_font_size","header_3_line_height","link_font","link_text_color","link_font_size","link_line_height","src","alt","title_text","url","disabled_on","button_url","button_text","button_bg_color__hover","button_bg_color__hover_enabled","button_border_color__hover","button_border_color__hover_enabled","button_text_color__hover","button_text_color__hover_enabled","title","header_level","animation"]}
AOS_DIVI_SCHEMA
 ,true);
 $stack=array();$cursor=0;$count=0;
 $gapOk=function($gap)use(&$stack){$parent=count($stack)?$stack[count($stack)-1]:null;return !preg_match('/[\[\]]/',$gap)&&(in_array($parent,array('et_pb_text','et_pb_blurb'),true)||trim($gap)==='');};
 preg_match_all('/\[(\/?)(et_pb_[a-z_]+)([^\[\]]*)\]/',$content,$matches,PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
 foreach($matches as $m){
  if(!$gapOk(substr($content,$cursor,$m[0][1]-$cursor)))return false;
  $cursor=$m[0][1]+strlen($m[0][0]);$count++;$name=$m[2][0];$raw=$m[3][0];
  if(!isset($schema['modules'][$name])||$count>1000)return false;
  if($m[1][0]==='/'){if(trim($raw)!==''||array_pop($stack)!==$name)return false;continue;}
  $parent=count($stack)?$stack[count($stack)-1]:null;
  if($parent?!in_array($name,$schema['modules'][$parent],true):$name!=='et_pb_section')return false;
  $seen=array();$end=0;
  preg_match_all('/\s+([a-z_][a-z0-9_]*)="([^"\[\]]*)"/',$raw,$attrs,PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
  foreach($attrs as $a){
   $key=$a[1][0];$value=$a[2][0];$base=preg_replace('/_(?:tablet|phone|last_edited)$/','',$key);
   if(trim(substr($raw,$end,$a[0][1]-$end))!==''||isset($seen[$key])||!in_array($key,$schema['attributes'][$name],true)||strlen($value)>2500||preg_match('/[\x00-\x1f<>\[\]\\\\]/',$value))return false;
   if(in_array($key,$schema['urls'],true)){
    $valid=false;
    if(in_array($key,array('url','button_url'),true))$valid=preg_match('/^tel:\+?[\d ()-]{7,25}$/',$value)||preg_match('~^/(?!/)[a-z0-9/_?\#=&%+.,\~-]*$~i',$value);
    if(!$valid){$u=wp_parse_url($value);$valid=is_array($u)&&($u['scheme']??'')==='https'&&!empty($u['host'])&&!isset($u['user'])&&!isset($u['pass'])&&!isset($u['port'])&&!preg_match('/[\x00-\x20<>"\x27\\\\]/',$value);}
    if(!$valid)return false;
   }elseif(in_array($key,$schema['text'],true)){
    if(preg_match('/["\x27]/',$value))return false;
   }else{
    if(!in_array($base,$schema['exact'],true))return false;
    if($key==='global_colors_info'){if($value!=='{}')return false;}elseif(in_array($base,array('font_icon','button_icon'),true)){
     if(!preg_match('/^(?:&#x[0-9a-f]{2,6};|&#[0-9]{2,7};|[a-f0-9]{2,6})(?:\|\|(?:divi|fa)\|\|[0-9]{3})?$/i',$value))return false;
    }elseif(!preg_match('/^[a-zA-Z0-9_\#|.,% ()+\-]*$/',$value)||preg_match('/(?:url|expression|javascript|data)\s*\(/i',$value))return false;
   }
   $seen[$key]=true;$end=$a[0][1]+strlen($a[0][0]);
  }
  if(trim(substr($raw,$end))!=='')return false;
  $stack[]=$name;
 }
 return $count>0&&!count($stack)&&$gapOk(substr($content,$cursor));
}

function aos_native_page_checks($c){
 $declared=array_key_exists('nativePageContractVersion',$c)||isset($c['contentFormat'])||isset($c['builder']);
 if(!$declared)return strpos((string)($c['html']??''),'[et_pb_')===false?array():array(array('code'=>'native_page_contract','ok'=>false,'message'=>'Prepare this Divi layout with the native page contract before publishing.'));
 $checks=array();$cap=aos_native_page_capability();
 $contract=($c['nativePageContractVersion']??null)===1&&($c['postType']??'')==='page'&&($c['contentFormat']??'')==='divi_shortcodes'&&($c['builder']??'')==='divi';
 $checks[]=array('code'=>'native_page_contract','ok'=>$contract,'message'=>$contract?'':'This native page format is not supported. Prepare it again with the matching Divi adapter.');
 $compatible=$cap['supported']&&($c['builderVersion']??null)===$cap['builderVersion'];
 $checks[]=array('code'=>'native_page_builder','ok'=>(bool)$compatible,'message'=>$compatible?'':'The active builder or its version differs from this approved page. Check the connection, then prepare and review the page again.');
 $safe=$contract&&aos_safe_divi_page($c['html']??null);
 $checks[]=array('code'=>'native_page_content','ok'=>$safe,'message'=>$safe?'':'This native page contains an unsupported module, setting, or invalid layout. Nothing was created.');
 return $checks;
}

function aos_new_post_checks($c,$id,$new){
 $checks=aos_native_page_checks($c);
 $conflict=$id||$new;
 $checks[]=array('code'=>'url_conflict','ok'=>!$conflict,'message'=>$id?'A page or post already exists at this address.':($new?'Only one new publication can be proposed at a time.':''));
 $postType=$c['postType']??'';$validType=in_array($postType,array('post','page'),true);
 $checks[]=array('code'=>'post_type','ok'=>$validType,'message'=>$validType?'':'Choose a post type of post or page.');
 $permission=$validType&&current_user_can($postType==='page'?'publish_pages':'publish_posts');
 $checks[]=array('code'=>'permission','ok'=>$permission,'message'=>$permission?'':'This WordPress user cannot publish '.($postType==='page'?'pages':'posts').' on this site.');
 $title=trim($c['title']??'')!=='';
 $checks[]=array('code'=>'title','ok'=>$title,'message'=>$title?'':'A title is required.');
 $slugOk=preg_match('/^[-a-z0-9]+$/',$c['slug']??'')===1;
 $checks[]=array('code'=>'slug','ok'=>$slugOk,'message'=>$slugOk?'':'The slug may only contain lowercase letters, numbers and hyphens.');
 $contentOk=is_string($c['html']??'')&&trim($c['html']??'')!=='';
 $checks[]=array('code'=>'content','ok'=>$contentOk,'message'=>$contentOk?'':'The article content is empty.');
 $safe=!$contentOk||aos_safe_html($c['html']);
 $checks[]=array('code'=>'html_safety','ok'=>$safe,'message'=>$safe?'':'The content contains markup WordPress will not allow.');
 // 0.5.2 — a category is part of what makes a new publication complete, the
 // same standing as its title, slug and content above: named, checked
 // independently, and never quietly defaulted to Uncategorized. But only for
 // a request that opted into the category contract: a legacy request that
 // never declares 'categoryContractVersion' (a genuine 0.5.1 Agency OS, or
 // any client that has not reviewed a category selection with this site)
 // must publish exactly as 0.5.1 always did, not be refused for a category
 // it was never asked to provide.
 $contractState=aos_category_contract_state($c);
 if($contractState==='invalid'){
  $checks[]=array('code'=>'category_contract','ok'=>false,'message'=>'This category selection uses a category-contract version this site does not support. Update Agency OS and try again.');
 }elseif($contractState==='valid'){
  foreach(aos_category_checks($c['categories']??null) as $cc)$checks[]=$cc;
 }
 return $checks;
}
// 0.5.2.2 — whether a new_post change carries a category-contract marker,
// and if so, whether it is one this plugin actually understands. Exactly
// three outcomes, never blurred together:
//  'none'    — no 'categoryContractVersion' key at all: a genuine 0.5.1-era
//              request, or any client that never reviewed a category
//              selection. Publishes exactly as 0.5.1 always did.
//  'valid'   — the key is present and is exactly the integer
//              AOS_CATEGORY_CONTRACT_VERSION. Category validation and
//              assignment apply in full.
//  'invalid' — the key is present with any other value: 0, a negative
//              number, a future version this plugin does not know how to
//              enforce, or a non-integer such as a numeric string. This is
//              never treated the same as 'none' and never allowed to bypass
//              category enforcement — aos_new_post_checks() refuses it
//              outright via the category_contract check, before any post is
//              created.
function aos_category_contract_state($c){
 if(!is_array($c)||!array_key_exists('categoryContractVersion',$c))return 'none';
 $v=$c['categoryContractVersion'];
 return (is_int($v)&&$v===AOS_CATEGORY_CONTRACT_VERSION)?'valid':'invalid';
}
function aos_category_contract($c){return aos_category_contract_state($c)==='valid';}
function aos_first_failure($checks){foreach($checks as $c)if(!$c['ok'])return $c;return null;}
// 0.5.1 — the exact characters an entity spells out, everywhere a string can
// carry one, including inside an attribute value. Two markup strings that
// decode to the same text differ only in HOW they spell the same characters
// (&#039; vs &apos; vs a literal '), never in what the page actually says or
// which attributes it carries — decoding is a stronger and simpler proof of
// that than re-running WordPress's own whole-document entity normaliser and
// hoping it happens to produce a byte-identical string.
function aos_html_decoded($html){return is_string($html)?html_entity_decode($html,ENT_QUOTES|ENT_HTML5,'UTF-8'):'';}
// 0.5.0.1 — what kind of difference WordPress's own save-time sanitisation
// makes, named so a person can tell entity spelling (harmless) apart from
// WordPress refusing markup or an attribute this site does not allow. A
// heuristic on tag names and a tag-only skeleton, not a full HTML diff —
// good enough to point at the right explanation, never to silently decide.
function aos_classify_html_change($original,$approved){
 if($approved===$original)return null;
 // 0.5.1 — the Q3 regression: WordPress re-spelled an apostrophe already
 // inside the supporting image's alt attribute (&#039; -> &apos;) and this
 // used to fall all the way through to "changed_attributes" because the old
 // check only ever compared the NORMALISED WHOLE STRING for exact equality,
 // which two different entity spellings will never satisfy. Decoding both
 // sides first proves the characters — attribute values included — are
 // identical, which is the only thing "no markup or attribute value
 // actually changed" needs to mean.
 if(aos_html_decoded($approved)===aos_html_decoded($original))return array('type'=>'entity_normalization','message'=>'WordPress represents the same characters with a different HTML entity spelling (for example a straight apostrophe as &#039; vs &apos;), including inside an attribute value. No markup or attribute value actually changed.');
 $normalizedOriginal=aos_normalized_html($original);
 $tagsIn=function($html){preg_match_all('/<([a-z][a-z0-9]*)\b[^>]*>/i',(string)$html,$m);return $m[1];};
 $before=$tagsIn($normalizedOriginal);$after=$tagsIn($approved);
 sort($before);sort($after);
 if($before!==$after)return array('type'=>'removed_markup','message'=>'WordPress removes tags this site does not allow in post content (for example script, iframe or object). Review the exact approved content below.');
 $skeleton=function($html){return preg_replace('/<([a-z][a-z0-9]*)\b[^>]*>/i','<$1>',(string)$html);};
 if($skeleton($normalizedOriginal)===$skeleton($approved))return array('type'=>'changed_attributes','message'=>'WordPress removes or changes attributes this site does not allow (for example an inline style or an unrecognised attribute) — not merely their entity spelling. Review the exact approved content below.');
 return array('type'=>'other','message'=>'WordPress would save this content differently. Review the exact approved content below.');
}
// 0.5.0 — a non-mutating dry run of the same new-publication checks /apply
// enforces, plus the two /apply cannot judge in advance: whether normalising
// this HTML the way WordPress will changes it, and whether the approved
// images are still exactly what was approved. Nothing here writes to the
// site; it only reads. requiredProtocol lets Agency OS refuse to proceed
// when this site's plugin is older than the package needs.
// Compare the original approved placeholder content after binding only the
// exact media already saved for this owned draft. Never upload during preflight.
function aos_native_bound_content($html,$media,$job){
 $saved=$job['media']??array();
 if(!is_string($html)||!is_array($media)||count($media)!==count($saved))return new WP_Error('native_preview_changed','The approved page images changed. Prepare a new preview.',array('status'=>409));
 foreach($media as $index=>$m){
  $owned=$saved[$index]??null;$asset=$m['assetId']??'';
  if(!is_array($m)||!is_array($owned)||!is_string($asset)||!preg_match('/^[a-zA-Z0-9_-]+$/D',$asset)||($m['sha256']??null)!==($owned['sha256']??null)||($m['alt']??null)!==($owned['alt']??null)||($m['role']??null)!==($owned['role']??null))return new WP_Error('native_preview_changed','The approved page images changed. Prepare a new preview.',array('status'=>409));
  $url=wp_get_attachment_url((int)$owned['attachmentId']);
  if(!$url)return new WP_Error('native_preview_changed','A prepared page image is unavailable. Prepare a new preview.',array('status'=>409));
  $html=str_replace('https://agency-os-image.invalid/'.$asset,esc_url($url),$html);
 }
 return $html;
}
function aos_preflight($r){
 $in=$r->get_json_params();
 $c=null;foreach(($in['changes']??array()) as $x){if(($x['kind']??'')==='new_post'){$c=$x;break;}}
 if(!$c)return new WP_Error('unsupported','Preflight validates a new publication (new_post) proposal',array('status'=>400));
 $url=$in['url']??'';$s=aos_snapshot($url);if(is_wp_error($s))return $s;
 $id=$s['postId']??0;
 // A new native page already has an owned private draft after preparation.
 // Rechecking that exact unchanged draft must not call it a URL collision.
 // A changed identity, content, builder, URL or another post remains blocked.
 $owned=null;$jobId=$in['jobId']??'';$proposalHash=$in['proposalHash']??'';
 if(($c['nativePageContractVersion']??null)===1&&is_string($jobId)&&preg_match('/^[a-f0-9-]{36}$/',$jobId)&&is_string($proposalHash)&&preg_match('/^[a-f0-9]{64}$/',$proposalHash)){
  $job=get_option('aos_job_'.$jobId);
  if(is_array($job)&&!empty($job['nativeDraft'])&&($job['jobId']??'')===$jobId&&($job['proposalHash']??'')===$proposalHash&&($job['url']??'')===$url){
   $receipt=aos_native_draft_receipt($job);
   if(is_wp_error($receipt))return $receipt;
   if((int)$id===(int)$receipt['postId']||!$id){
    $bound=aos_native_bound_content($c['html']??null,$in['media']??array(),$job);
    if(is_wp_error($bound))return $bound;
    if($bound!==($job['expected']['content']??null)||($c['title']??null)!==($job['newTitle']??null)||($c['slug']??null)!==($job['newSlug']??null))return new WP_Error('native_preview_changed','The proposed native page differs from its prepared draft. Prepare a new preview.',array('status'=>409));
    $owned=$receipt;$id=0;
   }
  }
 }
 $checks=aos_new_post_checks($c,$id,null);
 $required=(int)($in['requiredProtocol']??0);
 $protocolOk=!$required||AOS_PROTOCOL_VERSION>=$required;
 $checks[]=array('code'=>'protocol','ok'=>$protocolOk,'message'=>$protocolOk?'':'This site runs Agency OS Publisher protocol '.AOS_PROTOCOL_VERSION.'; this package needs protocol '.$required.' or later. Update the plugin.');
 $media=$in['media']??array();$mediaOk=true;$mediaMessage='';
 if(!is_array($media)||count($media)>10){$mediaOk=false;$mediaMessage='Too many images.';}
 elseif(count($media)&&!current_user_can('upload_files')){$mediaOk=false;$mediaMessage='Image upload permission is required.';}
 else foreach($media as $m){$bytes=base64_decode($m['base64']??'',true);$size=$bytes?getimagesizefromstring($bytes):false;if(!$bytes||strlen($bytes)>12000000||!$size||!in_array($size['mime'],array('image/png','image/jpeg','image/webp'),true)||$size['mime']!==($m['mimeType']??'')||hash('sha256',$bytes)!==($m['sha256']??'')){$mediaOk=false;$mediaMessage='An approved image is invalid or inaccessible.';break;}}
 $checks[]=array('code'=>'media','ok'=>$mediaOk,'message'=>$mediaMessage);
 // 0.5.0.1 — the exact content WordPress would accept: the same
 // normalisation and wp_kses_post() sanitisation aos_safe_html() judges
 // against, computed here so preflight can show it rather than only refuse.
 $sanitized=null;$htmlChange=null;
 if(is_string($c['html']??'')&&trim($c['html'])!==''){$approved=wp_kses_post(aos_normalized_html($c['html']));if($approved!==$c['html']){$sanitized=$approved;$htmlChange=aos_classify_html_change($c['html'],$approved);}}
 $checks[]=array('code'=>'html_normalized','ok'=>$sanitized===null,'message'=>$sanitized===null?'':'WordPress will save this content differently ('.($htmlChange['type']??'other').'). Review the exact WordPress-approved HTML, adopt it, then check with WordPress again before publishing.');
 $ok=aos_first_failure($checks)===null;
 return array('ok'=>$ok,'checks'=>$checks,'postType'=>(string)($c['postType']??''),'slug'=>(string)($c['slug']??''),'title'=>(string)($c['title']??''),'sanitizedHtml'=>$sanitized,'htmlChange'=>$htmlChange,'protocol'=>AOS_PROTOCOL_VERSION,'version'=>AOS_PLUGIN_VERSION,'preflightedAt'=>gmdate('c'));
}
// Explicit preparation action: save a private, owned native draft. /preflight
// remains read-only. Publishing reuses this exact draft after review.
function aos_native_draft_receipt($j){
 $id=(int)($j['postId']??0);$post=$id?get_post($id):null;
 if(!$post||empty($j['nativeDraft'])||empty($j['nativePage'])||$post->post_type!=='page'||$post->post_status!=='draft'||get_post_meta($id,'_aos_job',true)!==$j['jobId']||!current_user_can('edit_post',$id))return new WP_Error('native_preview_changed','The private Atlas page draft is unavailable or has changed. Prepare a new preview; nothing was published.',array('status'=>409));
 $cap=aos_native_page_capability();if(!$cap['supported']||$cap['builderVersion']!==$j['nativePage']['builderVersion'])return new WP_Error('native_page_builder','The active Divi version changed. Prepare this page again.',array('status'=>409));
 $s=aos_state($id);$ok=$post->post_title===$j['newTitle']&&$post->post_name===($j['newSlug']??'')&&get_post_meta($id,'_et_pb_use_builder',true)==='on'&&get_post_meta($id,'_et_pb_built_for_post_type',true)==='page';
 foreach($j['expected'] as $key=>$value)$ok=$ok&&array_key_exists($key,$s)&&$s[$key]===$value;
 foreach(($j['media']??array()) as $m){$file=function_exists('wp_get_original_image_path')?wp_get_original_image_path($m['attachmentId']):get_attached_file($m['attachmentId']);$ok=$ok&&$file&&file_exists($file)&&hash_file('sha256',$file)===$m['sha256']&&get_post_meta($m['attachmentId'],'_wp_attachment_image_alt',true)===$m['alt'];}
 if(!$ok)return new WP_Error('native_preview_changed','The private page draft or its approved images changed after preparation. Atlas preserved it. Prepare a new preview before publishing.',array('status'=>409));
 return array('version'=>1,'jobId'=>$j['jobId'],'proposalHash'=>$j['proposalHash'],'postId'=>$id,'postType'=>'page','status'=>'draft','public'=>false,'ready'=>true,'nativePage'=>$j['nativePage'],'checkedAt'=>gmdate('c'));
}
function aos_publish_native_draft($in,$j){
 $id=(int)$j['postId'];
 if(get_post_status($id)==='publish')return aos_receipt($j);
 $draft=aos_native_draft_receipt($j);if(is_wp_error($draft))return $draft;
 if(!current_user_can('publish_pages'))return new WP_Error('permission','This WordPress user cannot publish pages.',array('status'=>403));
 if(($in['url']??'')!==$j['url']||($in['beforeHash']??'')!==($j['before']['beforeHash']??''))return new WP_Error('changed','The approved target changed. Prepare this page again.',array('status'=>409));
 $other=url_to_postid($j['url']);if($other&&(int)$other!==$id)return new WP_Error('url_conflict','Another page now uses the approved address. Nothing was published.',array('status'=>409));
 $lock='aos_lock_'.hash('sha256',$j['url']);if(!add_option($lock,$j['jobId'],'',false))return new WP_Error('busy','Another approved change is being applied to this page.',array('status'=>409));
 try{
  $draft=aos_native_draft_receipt($j);if(is_wp_error($draft))return $draft;
  $other=url_to_postid($j['url']);if($other&&(int)$other!==$id)return new WP_Error('url_conflict','Another page now uses the approved address.',array('status'=>409));
  $published=wp_update_post(wp_slash(array('ID'=>$id,'post_status'=>'publish')),true);clean_post_cache($id);
  $receipt=is_wp_error($published)?$published:aos_receipt($j);
  if(is_wp_error($receipt)||empty($receipt['verified'])||get_permalink($id)!==$j['url']){
   wp_update_post(wp_slash(array('ID'=>$id,'post_status'=>'draft')),true);clean_post_cache($id);
   if(get_post_status($id)!=='draft')return new WP_Error('native_publish_recovery','The page publication did not verify and could not be returned to draft. Review page ID '.$id.' immediately.',array('status'=>500,'postId'=>$id));
   return new WP_Error('native_publish_verify','The page publication did not verify. Its private draft is preserved for review; the page is not public.',array('status'=>409,'postId'=>$id));
  }
  $j['nativeDraft']=false;update_option('aos_job_'.$j['jobId'],$j,false);aos_schedule_attachment_metadata($j['media']??array());
  return $receipt;
 }finally{delete_option($lock);}
}
// Divi's generated module CSS is built during a normal front-end request.
// A REST template include omits that lifecycle and can look structurally
// correct while rendering unstyled. Use a same-site authenticated frontend
// request for this one owned draft; never issue a public preview token.
function aos_native_frontend_shape(){
 return isset($_GET['aos_native_preview'],$_GET['aos_native_hash'])&&is_string($_GET['aos_native_preview'])&&is_string($_GET['aos_native_hash'])&&preg_match('/^[a-f0-9-]{36}$/D',$_GET['aos_native_preview'])&&preg_match('/^[a-f0-9]{64}$/D',$_GET['aos_native_hash']);
}
if(isset($_GET['aos_native_preview'])&&!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);
add_filter('application_password_is_api_request',function($api){return $api||aos_native_frontend_shape();});
function aos_native_frontend_job(){
 if(!aos_native_frontend_shape())return new WP_Error('native_preview_access','Native page preview unavailable.',array('status'=>403));
 $j=get_option('aos_job_'.$_GET['aos_native_preview']);
 if(!$j||!is_string($j['proposalHash']??null)||!hash_equals($j['proposalHash'],$_GET['aos_native_hash']))return new WP_Error('native_preview_access','Native page preview unavailable.',array('status'=>403));
 $receipt=aos_native_draft_receipt($j);if(is_wp_error($receipt))return $receipt;
 return $j;
}
add_action('parse_request',function($wp){
 if(!isset($_GET['aos_native_preview']))return;
 if(function_exists('nocache_headers'))nocache_headers();
 $j=aos_native_frontend_job();
 if(is_wp_error($j))wp_die('Native page preview unavailable.','',array('response'=>403));
 $GLOBALS['aos_native_frontend_job']=$j;
 $wp->query_vars=array('page_id'=>(int)$j['postId'],'post_type'=>'page','post_status'=>'draft','preview'=>true);
});
add_filter('redirect_canonical',function($url){return !empty($GLOBALS['aos_native_frontend_job'])?false:$url;});
add_action('template_redirect',function(){
 if(empty($GLOBALS['aos_native_frontend_job']))return;
 $j=aos_native_frontend_job();
 if(is_wp_error($j)||(int)get_queried_object_id()!==(int)$j['postId'])wp_die('Native page preview unavailable.','',array('response'=>403));
 if(function_exists('nocache_headers'))nocache_headers();
 if(!headers_sent()){header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');header('Vary: Authorization',false);header('X-Robots-Tag: noindex, nofollow, noarchive');}
 show_admin_bar(false);wp_set_current_user(0);
},0);
function aos_native_page_render($r){
 if(function_exists('nocache_headers'))nocache_headers();
 $in=$r->get_json_params();$jobId=$in['jobId']??'';
 if(!is_string($jobId)||!preg_match('/^[a-f0-9-]{36}$/D',$jobId))return new WP_Error('invalid','Choose an Atlas native page draft.',array('status'=>400));
 $j=get_option('aos_job_'.$jobId);if(!$j||($in['proposalHash']??'')!==$j['proposalHash'])return new WP_Error('native_preview_missing','Prepare this native page preview first.',array('status'=>409));
 $receipt=aos_native_draft_receipt($j);if(is_wp_error($receipt))return $receipt;
 $auth=method_exists($r,'get_header')?(string)$r->get_header('authorization'):(string)($_SERVER['HTTP_AUTHORIZATION']??'');
 if(!preg_match('/^Basic [a-z0-9+\/=]+$/iD',$auth))return new WP_Error('native_preview_auth','Use this website’s application-password connection to render its private Divi preview.',array('status'=>409));
 $base=home_url('/');$host=strtolower((string)wp_parse_url($base,PHP_URL_HOST));$incoming=strtolower(explode(':',(string)($_SERVER['HTTP_HOST']??''))[0]);
 if(wp_parse_url($base,PHP_URL_SCHEME)!=='https'||!$host||$host!==$incoming)return new WP_Error('native_preview_site','The private preview must use the exact HTTPS website connection.',array('status'=>409));
 $url=add_query_arg(array('aos_native_preview'=>$jobId,'aos_native_hash'=>$j['proposalHash'],'preview'=>'true'),$base);
 $response=wp_remote_get($url,array('timeout'=>45,'redirection'=>0,'sslverify'=>true,'reject_unsafe_urls'=>true,'limit_response_size'=>3000001,'headers'=>array('Authorization'=>$auth,'User-Agent'=>'AgencyOS-Publisher/'.AOS_PLUGIN_VERSION.' native Divi preview','Cache-Control'=>'no-store')));
 unset($auth);
 if(is_wp_error($response))return new WP_Error('native_preview_template','WordPress could not load its authenticated private page preview. The draft is preserved.',array('status'=>409));
 $html=wp_remote_retrieve_body($response);
 if(wp_remote_retrieve_response_code($response)!==200||!is_string($html)||strlen($html)>3000000||strpos($html,'<html')===false||strpos($html,'et_pb_section')===false)return new WP_Error('native_preview_template','The native Divi frontend render was incomplete. The draft is preserved.',array('status'=>409));
 return array_merge($receipt,array('url'=>$j['url'],'html'=>$html,'mode'=>'native_divi_private_draft'));
}

function aos_apply($r,$draftOnly=false){
 $in=$r->get_json_params();if(!preg_match('/^[a-f0-9-]{36}$/',$in['jobId']??'')||!preg_match('/^[a-f0-9]{64}$/',$in['proposalHash']??''))return new WP_Error('invalid','Invalid approval identifier',array('status'=>400));
 $key='aos_job_'.$in['jobId'];$old=get_option($key);if($old){if($old['proposalHash']!==$in['proposalHash'])return new WP_Error('conflict','Approval identifier already used',array('status'=>409));if(isset($old['cleanup']))return aos_cleanup_status($old);if(!empty($old['nativeDraft']))return $draftOnly?aos_native_draft_receipt($old):aos_publish_native_draft($in,$old);if($draftOnly)return new WP_Error('native_preview_published','This page is already published; prepare a new change to edit it.',array('status'=>409));return aos_receipt($old);}
 $url=$in['url']??'';$s=aos_snapshot($url);if(is_wp_error($s))return $s;
 // Optional metadata automation contract. Restoration can only replay the
 // original job's saved values, including an empty override (site default).
 $automatic=array_key_exists('metadataAutomationVersion',$in);$restore=isset($in['restoreOf']);$metadataFields=array();
 if($restore&&!$automatic)return new WP_Error('invalid','Restoration requires the metadata automation contract',array('status'=>400));
 if($automatic){
  $changes=$in['changes']??array();
  if($in['metadataAutomationVersion']!==1||!is_array($changes)||count($changes)!==1||!is_array($changes[0])||($changes[0]['kind']??'')!=='metadata'||!is_array($in['media']??array())||count($in['media']??array())||isset($in['publishing']))return new WP_Error('unsupported','Automatic publishing is limited to search titles and descriptions',array('status'=>409));
  foreach($changes[0] as $f=>$value)if(!in_array($f,array('kind','title','description','reason'),true))return new WP_Error('unsupported','Unsupported automatic metadata field',array('status'=>409));
  foreach(array('title','description') as $f)if(array_key_exists($f,$changes[0]))$metadataFields[]=$f;
  if(!count($metadataFields))return new WP_Error('invalid','No metadata fields selected',array('status'=>400));
  if($restore){
   if(!is_string($in['restoreOf'])||!preg_match('/^[a-f0-9-]{36}$/',$in['restoreOf']))return new WP_Error('invalid','Invalid original operation',array('status'=>400));
   $original=get_option('aos_job_'.$in['restoreOf']);
   if(!$original||empty($original['metadataFields'])||!empty($original['restoreOf'])||isset($original['cleanup'])||($original['postId']??0)!==($s['postId']??-1)||($original['url']??'')!==$url||$metadataFields!==$original['metadataFields'])return new WP_Error('changed','The original metadata operation does not match this page and field scope',array('status'=>409));
   foreach($metadataFields as $f)if(($s[$f]??null)!==($original['expected'][$f]??null)||!array_key_exists($f,$original['before'])||$changes[0][$f]!==$original['before'][$f])return new WP_Error('changed','Restoration would overwrite newer wording or does not match the original values',array('status'=>409));
  }
 }
 if(($in['beforeHash']??'')!==$s['beforeHash'])return new WP_Error('changed','Website changed since preparation. Prepare again.',array('status'=>409));
 // 0.3.1 — expected state starts from the whole current state, so a change to
 // an existing page expects its author, comment setting and layout to stay
 // exactly as found. 0.3.0 began recording those three in the saved state
 // without expecting them, so every existing-page change verified false.
 $id=$s['postId']??0;$expected=array('content'=>$s['content'],'title'=>$s['title'],'description'=>$s['description'],'schema'=>$s['schema']??'','featured'=>(int)($s['featured']??0),'author'=>(int)($s['author']??0),'commentStatus'=>(string)($s['commentStatus']??''),'pingStatus'=>(string)($s['pingStatus']??''),'layout'=>(string)($s['layout']??''),'focusKeyword'=>(string)($s['focusKeyword']??''));$new=null;$seo=false;
 $changes=$in['changes']??array();if(!is_array($changes)||!count($changes)||count($changes)>10)return new WP_Error('invalid','No valid changes',array('status'=>400));
 foreach($changes as $c){$kind=$c['kind']??'';
  if($kind==='metadata'){
   // 0.4.0 — the SEO package is Rank Math's title, description and focus
   // keyword (its documented post meta). A new post saves them whether or not
   // Rank Math is active, and the receipt says whether it is; an existing
   // page's SEO is still only edited where Rank Math will show it.
   $seo=true;
   foreach(array('title','description','focusKeyword') as $f)if(isset($c[$f])){if(!is_string($c[$f])||(!$restore&&trim($c[$f])==='')||strlen($c[$f])>2000||strip_tags($c[$f])!==$c[$f])return new WP_Error('invalid','Invalid metadata',array('status'=>400));$expected[$f]=$f==='focusKeyword'?sanitize_text_field($c[$f]):$c[$f];}
  }elseif($kind==='new_post'){
   // 0.5.0 — the same checks /preflight ran, so a proposal that passed
   // preflight cannot be refused here for a reason preflight did not name.
   $failed=aos_first_failure(aos_new_post_checks($c,$id,$new));if($failed)return new WP_Error($failed['code'],$failed['message'],array('status'=>409));
   $new=$c;$new['html']=aos_normalized_html($c['html']);$expected['content']=$new['html'];
  }elseif($kind==='schema'){
   if(!is_array($c['value']??null)||($c['value']['@context']??'')!=='https://schema.org'||empty($c['value']['@type']))return new WP_Error('schema','Provide valid JSON-LD',array('status'=>400));$expected['schema']=$c['value'];
  }elseif(in_array($kind,array('replace_text','replace_html','append_html'),true)){
   if(!$id)return new WP_Error('missing','Read the existing page before editing it',array('status'=>409));
   if($kind==='append_html'){
    if(strpos($expected['content'],'[et_pb_')!==false||get_post_meta($id,'_et_pb_use_builder',true)==='on')return new WP_Error('divi','This Divi page requires an exact section replacement to preserve its layout',array('status'=>409));
    if(!aos_safe_html($c['html']??''))return new WP_Error('html','Content contains unsupported markup',array('status'=>400));$expected['content'].="\n".aos_normalized_html($c['html']);
   }else{
    $before=$c['before']??'';$after=$kind==='replace_text'?($c['after']??''):aos_normalized_html($c['html']??'');
    if(!is_string($before)||trim($before)===''||!is_string($after)||trim($after)===''||substr_count($expected['content'],$before)!==1)return new WP_Error('ambiguous','The exact section must occur once in this page',array('status'=>409));
    if($kind==='replace_text'&&preg_match('/[<>\[\]]/',$before.$after))return new WP_Error('markup','Text edits must preserve markup',array('status'=>409));
    if($kind==='replace_html'&&(!aos_safe_html($after)||strpos($before,'[et_pb_')!==false||strpos($after,'[et_pb_')!==false))return new WP_Error('markup','Use an exact content section without replacing Divi layout markup',array('status'=>409));
    $expected['content']=str_replace($before,$after,$expected['content']);
   }
  }else return new WP_Error('unsupported','Unsupported publishing action',array('status'=>409));
 }
 if($draftOnly&&(!$new||($new['nativePageContractVersion']??null)!==1))return new WP_Error('native_preview_contract','Only a supported native page can be prepared as a private preview.',array('status'=>409));
 if($new&&($new['nativePageContractVersion']??null)===1&&!$draftOnly)return new WP_Error('native_preview_required','Prepare and review the native page preview before publishing.',array('status'=>409));
 if(!$id&&!$new)return new WP_Error('missing','The target page does not exist',array('status'=>409));
 if($seo&&!$new&&!defined('RANK_MATH_VERSION'))return new WP_Error('rankmath','Activate Rank Math before applying SEO metadata',array('status'=>409));
 // 0.3.0 — how a new post publishes. An existing page keeps its author,
 // comment setting and layout exactly as they are.
 $pub=is_array($in['publishing']??null)?$in['publishing']:array();$layoutRequested='';$layoutMechanism=null;
 if($new){
  $expected['commentStatus']=(($pub['commentStatus']??'closed')==='open')?'open':'closed';
  $expected['pingStatus']=(($pub['pingStatus']??'open')==='closed')?'closed':'open';
  if(isset($pub['focusKeyword'])&&is_string($pub['focusKeyword'])&&trim($pub['focusKeyword'])!==''){if(strlen($pub['focusKeyword'])>200||strip_tags($pub['focusKeyword'])!==$pub['focusKeyword'])return new WP_Error('invalid','Invalid focus keyword',array('status'=>400));$expected['focusKeyword']=sanitize_text_field($pub['focusKeyword']);}
  $author=(int)($pub['authorId']??0);
  if($author){if(!get_userdata($author)||!user_can($author,'edit_posts'))return new WP_Error('author','Choose an author who can write posts on this site',array('status'=>409));if($author!==get_current_user_id()&&!current_user_can('edit_others_posts'))return new WP_Error('author','This connection cannot publish as another author',array('status'=>403));}
  else $author=get_current_user_id();
  $expected['author']=$author;
  $byline=$pub['byline']??'';if(!is_string($byline)||preg_match_all('/./us',$byline)>120||preg_match('/[<>\x00-\x1f]/u',$byline))return new WP_Error('author','Enter a plain-text author name up to 120 characters',array('status'=>400));$expected['byline']=trim($byline);
  $layoutRequested=(string)($pub['layout']??'');
  if($layoutRequested==='full_width'){$mech=aos_layout_mechanism();$layoutMechanism=$mech['mechanism'];$expected['layout']=$mech['mechanism']?$mech['value']:'';}
  else $expected['layout']='';
 }
 $media=$in['media']??array();if(!is_array($media)||count($media)>10)return new WP_Error('media','Too many images',array('status'=>400));if(count($media)&&!current_user_can('upload_files'))return new WP_Error('media','Image upload permission is required',array('status'=>403));foreach($media as $m){$bytes=base64_decode($m['base64']??'',true);$size=$bytes?getimagesizefromstring($bytes):false;if(!$bytes||strlen($bytes)>12000000||!$size||!in_array($size['mime'],array('image/png','image/jpeg','image/webp'),true)||$size['mime']!==($m['mimeType']??'')||hash('sha256',$bytes)!==($m['sha256']??''))return new WP_Error('media','An approved image is invalid or has changed',array('status'=>400));}
 $j=array('jobId'=>$in['jobId'],'proposalHash'=>$in['proposalHash'],'postId'=>$id,'url'=>$url,'before'=>$s,'expected'=>$expected,'layoutRequested'=>$layoutRequested,'layoutMechanism'=>$layoutMechanism);if($automatic){$j['metadataFields']=$metadataFields;if($restore)$j['restoreOf']=$in['restoreOf'];}if($new){$j['newTitle']=$new['title'];$j['newSlug']=$new['slug'];}if($new&&($new['nativePageContractVersion']??null)===1)$j['nativePage']=array('contractVersion'=>1,'builder'=>'divi','builderVersion'=>$new['builderVersion'],'contentFormat'=>'divi_shortcodes');if($draftOnly)$j['nativeDraft']=true;
 $lock='aos_lock_'.hash('sha256',$url);if(!add_option($lock,$in['jobId'],'',false))return new WP_Error('busy','Another approved change is being applied to this page',array('status'=>409));
 try{
  $fresh=aos_snapshot($url);if(is_wp_error($fresh)||$fresh['beforeHash']!==$s['beforeHash'])return new WP_Error('changed','Website changed during preparation',array('status'=>409));
  if(!add_option($key,$j,'',false))return new WP_Error('busy','This approval is already being processed',array('status'=>409));
  // 0.5.2.2 — every attachment, raw uploaded file, and newly created
  // category term this ATTEMPT creates is tracked here from the moment the
  // aos_job option exists, so $rollback can undo exactly what this attempt
  // did on any failure from this point on — including a media-upload or
  // attachment-creation failure that happens before a new post's draft is
  // even inserted. $createdPostId is only ever set once wp_insert_post
  // succeeds for a NEW post; an existing page being edited is never
  // assigned to it and is therefore never touched by rollback.
  $rawFiles=array();$createdTerms=array();$createdPostId=null;
  // 0.5.2.3 — the post may already be live when this runs (a failure
  // discovered only after the publish transition, e.g. final verification).
  // A failed attempt to force it back to non-public must never stop the
  // rollback from still attempting a hard delete — the old behavior gave up
  // right there, which could leave a public article behind with an error
  // message that falsely claimed it had been moved to draft. Both steps are
  // attempted unconditionally, and only the post's OWN, freshly re-read
  // status after both — never wp_update_post()'s or wp_delete_post()'s own
  // return value — decides what actually happened.
  $rollback=function($error)use($key,&$j,&$rawFiles,&$createdTerms,&$createdPostId){
   $postRetained=false;$postPublic=false;$finalStatus=false;
   if($createdPostId){
    if(get_post_status($createdPostId)==='publish')wp_update_post(wp_slash(array('ID'=>$createdPostId,'post_status'=>'draft')),true);
    wp_delete_post($createdPostId,true);
    $finalStatus=get_post_status($createdPostId);
    if($finalStatus!==false){$postRetained=true;$postPublic=($finalStatus==='publish');}
   }
   if($postRetained){
    // 0.5.2.3 — the post could not be fully removed. Its media and any
    // newly created category terms are still legitimately referenced by
    // it — whether it is a dangerously-retained PUBLIC article or a
    // recoverable non-public draft — so neither is deleted here; doing so
    // would damage a post this rollback failed to remove. The job option
    // is preserved (never delete_option), with the post's own verified
    // status recorded, so a retry for this exact job can never fall
    // through to a normal — and possibly misleadingly successful —
    // receipt; see aos_cleanup_status() and its use in aos_apply().
    $j['cleanup']=array('postId'=>$createdPostId,'postStatus'=>$finalStatus,'public'=>$postPublic);
    update_option($key,$j,false);
    if($postPublic)return new WP_Error('cleanup_required_critical','This article could not be safely removed after a publishing failure and may still be publicly visible. Immediate manual action is required: review and unpublish or delete post ID '.$createdPostId.' on this site.',array('status'=>500,'postId'=>$createdPostId));
    return new WP_Error('cleanup_required','This publishing attempt failed and the resulting draft could not be fully removed automatically. It has been verified non-public (post ID '.$createdPostId.'), but needs manual review before retrying this job.',array('status'=>409,'postId'=>$createdPostId));
   }
   // 0.5.2.2 — the post is confirmed gone (or never existed for this
   // attempt); every resource this attempt created is now genuinely
   // orphaned and safe to remove. A newly created category term is removed
   // only once it is confirmed still unused (WordPress decrements a term's
   // count as its last post is removed above, so a count of zero here
   // means nothing else references it).
   foreach(($j['media']??array()) as $m)wp_delete_attachment($m['attachmentId'],true);
   foreach($rawFiles as $f)wp_delete_file($f);
   foreach($createdTerms as $t){$term=get_term($t['id'],'category');if($term&&!is_wp_error($term)&&(int)($term->count??0)===0)wp_delete_term($t['id'],'category');}
   delete_option($key);
   return $error;
  };
  // 0.5.2.2 — a raw uploaded file is tracked in $rawFiles the instant it
  // exists on disk, before it becomes an attachment; popped back off once
  // wp_insert_attachment succeeds, since it is then owned by that
  // attachment's own cleanup instead.
  $j['media']=array();if(count($media)){foreach($media as $m){$ext=array('image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp')[$m['mimeType']];$name=sanitize_file_name((string)($m['fileName']??''));if($name===''||!preg_match('/\.(png|jpe?g|webp)$/i',$name))$name=sanitize_file_name($in['jobId'].'-'.$m['assetId'].'.'.$ext);$upload=wp_upload_bits($name,null,base64_decode($m['base64'],true));if($upload['error'])return $rollback(new WP_Error('media',$upload['error'],array('status'=>500)));$rawFiles[]=$upload['file'];$aid=wp_insert_attachment(array('post_mime_type'=>$m['mimeType'],'post_title'=>sanitize_text_field($m['alt']),'post_status'=>'inherit'),$upload['file']);if(is_wp_error($aid)||!$aid)return $rollback(new WP_Error('media','Could not save image',array('status'=>500)));array_pop($rawFiles);update_post_meta($aid,'_wp_attachment_image_alt',wp_slash($m['alt']));$expected['content']=str_replace('https://agency-os-image.invalid/'.$m['assetId'],esc_url(wp_get_attachment_url($aid)),$expected['content']);if($m['role']==='featured')$expected['featured']=(int)$aid;$j['media'][]=array('attachmentId'=>$aid,'sha256'=>$m['sha256'],'alt'=>$m['alt'],'role'=>(string)($m['role']??''));}$j['expected']=$expected;update_option($key,$j,false);}
  if($new){
   // 0.5.2.1 — fail closed. A new article used to go live (post_status
   // publish) before its categories were even resolved: if resolution,
   // creation or assignment then failed, the article was already public,
   // Uncategorized. It now starts as a draft — never publicly visible —
   // and is flipped to publish only once every required element of the
   // approved package (categories included) is applied and verified. Any
   // failure along the way rolls the whole attempt back through the
   // $rollback closure above and returns a specific WP_Error; nothing here
   // ever falls through to publish on an incomplete package.
   $insert=array('post_type'=>$new['postType'],'post_status'=>'draft','post_title'=>$new['title'],'post_name'=>$new['slug'],'post_content'=>$expected['content'],'post_author'=>$expected['author'],'comment_status'=>$expected['commentStatus'],'ping_status'=>$expected['pingStatus'],'meta_input'=>array('_aos_job'=>$in['jobId'],'_aos_byline'=>$expected['byline']));
   if($layoutMechanism==='template')$insert['page_template']=$expected['layout'];
   $id=wp_insert_post(wp_slash($insert),true);
   if(is_wp_error($id))return $rollback($id);
   $createdPostId=$id;
   if($layoutMechanism==='divi')update_post_meta($id,'_et_pb_page_layout',$expected['layout']);
   if(isset($j['nativePage'])){update_post_meta($id,'_et_pb_use_builder','on');update_post_meta($id,'_et_pb_built_for_post_type','page');if(get_post_meta($id,'_et_pb_use_builder',true)!=='on'||get_post_meta($id,'_et_pb_page_layout',true)!=='et_full_width_page')return $rollback(new WP_Error('native_page_save','The native Divi builder settings could not be verified. The page remains unpublished.',array('status'=>500)));}
   $j['postId']=$id;update_option($key,$j,false);
   // 0.5.2.1 — categories are resolved and assigned once the post exists. A
   // new category is created here, never at preflight, only when the
   // operator explicitly approved it; get_term_by('slug',...) makes a
   // retried apply idempotent — it finds the term a previous attempt
   // already created rather than making a duplicate. Every failure here —
   // creation, assignment, or the post-assignment readback — is a real
   // WP_Error with a specific message, never silently swallowed into null.
   if(aos_category_contract($new)){
    $catSel=$new['categories']??null;
    $resolveOrCreate=function($sel)use(&$createdTerms){
     if(!is_array($sel))return null;
     if(($sel['source']??'')==='existing'){$tid=(int)($sel['id']??0);return ($tid>0&&term_exists($tid,'category'))?$tid:null;}
     if(($sel['source']??'')==='new'&&!empty($sel['approved'])){
      if(!current_user_can('manage_categories'))return new WP_Error('category_permission','This WordPress user cannot create the approved category on this site.',array('status'=>403));
      $slug=sanitize_title((string)($sel['slug']??$sel['name']));
      $existing=get_term_by('slug',$slug,'category');
      if($existing&&!is_wp_error($existing))return (int)$existing->term_id;
      $result=wp_insert_term(wp_slash((string)$sel['name']),'category',array('slug'=>$slug));
      if(is_wp_error($result))return new WP_Error('category_create','The approved category could not be created: '.$result->get_error_message(),array('status'=>500));
      $createdTerms[]=array('id'=>(int)$result['term_id'],'slug'=>$slug);
      return (int)$result['term_id'];
     }
     return null;
    };
    $primaryId=$resolveOrCreate($catSel['primary']??null);
    if(is_wp_error($primaryId))return $rollback($primaryId);
    if(!$primaryId)return $rollback(new WP_Error('category_primary','The approved primary category could not be resolved on this site.',array('status'=>409)));
    $secondaryId=($catSel['secondary']??null)?$resolveOrCreate($catSel['secondary']):null;
    if(is_wp_error($secondaryId))return $rollback($secondaryId);
    $categoryIds=array_values(array_unique(array_filter(array($primaryId,$secondaryId))));
    $assigned=wp_set_post_categories($id,$categoryIds);
    if(is_wp_error($assigned))return $rollback(new WP_Error('category_assign','The approved categories could not be saved: '.$assigned->get_error_message(),array('status'=>500)));
    if(!is_array($assigned))return $rollback(new WP_Error('category_assign','WordPress returned an unexpected result assigning the approved categories.',array('status'=>500)));
    $confirmedIds=wp_get_post_categories($id);
    // 0.5.2.2 — the readback itself can fail (a WP_Error, or some other
    // non-array value) after assignment reported success. Never pass either
    // into in_array() below; a readback that cannot be trusted is treated
    // exactly like a readback that contradicts the approved selection.
    if(is_wp_error($confirmedIds)||!is_array($confirmedIds))return $rollback(new WP_Error('category_verify','The approved categories could not be confirmed after saving.',array('status'=>500)));
    if(!in_array($primaryId,$confirmedIds,true))return $rollback(new WP_Error('category_verify','The approved primary category was not confirmed assigned after saving.',array('status'=>500)));
    if($secondaryId&&!in_array($secondaryId,$confirmedIds,true))return $rollback(new WP_Error('category_verify','The approved additional category was not confirmed assigned after saving.',array('status'=>500)));
    if(defined('RANK_MATH_VERSION'))update_post_meta($id,'rank_math_primary_category',$primaryId);
    $j['categories']=array('primaryId'=>$primaryId,'secondaryId'=>$secondaryId,'created'=>array_map(function($t){return $t['slug'];},$createdTerms));
    update_option($key,$j,false);
   }
   if($expected['title']!==$s['title'])update_post_meta($id,'rank_math_title',wp_slash($expected['title']));
   if($expected['description']!==$s['description'])update_post_meta($id,'rank_math_description',wp_slash($expected['description']));
   if($expected['focusKeyword']!==($s['focusKeyword']??''))update_post_meta($id,'rank_math_focus_keyword',wp_slash($expected['focusKeyword']));
   if($expected['schema']!==($s['schema']??''))update_post_meta($id,'_aos_schema',wp_slash($expected['schema']));
   if($expected['featured']!==($s['featured']??0))set_post_thumbnail($id,$expected['featured']);
   foreach($j['media'] as $m)wp_update_post(array('ID'=>$m['attachmentId'],'post_parent'=>$id));
   clean_post_cache($id);
   if($draftOnly){$j['expected']=$expected;update_option($key,$j,false);$draft=aos_native_draft_receipt($j);if(is_wp_error($draft))return $rollback($draft);aos_schedule_attachment_metadata($j['media']??array());return $draft;}
   // 0.5.2.1 — the post becomes publicly visible only now, and only once.
   $published=wp_update_post(wp_slash(array('ID'=>$id,'post_status'=>'publish')),true);
   if(is_wp_error($published))return $rollback($published);
   clean_post_cache($id);
   $receipt=aos_receipt($j);
   // 0.5.2.2 — aos_receipt() itself can return a WP_Error (for example if
   // access is re-checked and fails); guard before ever indexing
   // ['verified'] on it, since PHP 8 fatally errors indexing a non-array
   // object. Either way, a receipt that is not a confirmed-verified array
   // rolls the whole attempt back — the post may already be live at this
   // point, which is exactly what $rollback's force-to-draft-then-delete
   // sequence above exists to undo.
   if(is_wp_error($receipt)||!is_array($receipt)||!$receipt['verified'])return $rollback(is_wp_error($receipt)?$receipt:new WP_Error('unverified','The approved publication could not be verified after publishing.',array('status'=>500)));
   aos_schedule_attachment_metadata($j['media']);
   return $receipt;
  }
  elseif($expected['content']!==$s['content']){$result=wp_update_post(wp_slash(array('ID'=>$id,'post_content'=>$expected['content'])),true);if(is_wp_error($result))return $result;}
  if($expected['title']!==$s['title'])update_post_meta($id,'rank_math_title',wp_slash($expected['title']));if($expected['description']!==$s['description'])update_post_meta($id,'rank_math_description',wp_slash($expected['description']));if($expected['focusKeyword']!==($s['focusKeyword']??''))update_post_meta($id,'rank_math_focus_keyword',wp_slash($expected['focusKeyword']));if($expected['schema']!==($s['schema']??''))update_post_meta($id,'_aos_schema',wp_slash($expected['schema']));if($expected['featured']!==($s['featured']??0))set_post_thumbnail($id,$expected['featured']);foreach($j['media'] as $m)wp_update_post(array('ID'=>$m['attachmentId'],'post_parent'=>$id));clean_post_cache($id);$receipt=aos_receipt($j);if(is_array($receipt)&&!empty($receipt['verified']))aos_schedule_attachment_metadata($j['media']);return $receipt;
 }finally{delete_option($lock);}
}
add_action('rest_api_init',function(){
 $permission=function(){return current_user_can('edit_posts');};
 register_rest_route('agency-os/v1','/capabilities',array('methods'=>'GET','permission_callback'=>$permission,'callback'=>function(){$layout=aos_layout_mechanism();return array('protocol'=>AOS_PROTOCOL_VERSION,'version'=>AOS_PLUGIN_VERSION,'media'=>true,'nativePages'=>aos_native_page_capability(),'preflight'=>true,'layoutReview'=>1,'rankMathScore'=>true,'kinds'=>array('metadata','replace_text','replace_html','append_html','new_post','schema'),'metadataAutomation'=>array('version'=>1,'restore'=>true),'site'=>home_url(),'authors'=>true,'publishing'=>array('comments'=>true,'pings'=>true,'author'=>true,'byline'=>true,'layout'=>array('fullWidth'=>(bool)$layout['mechanism'],'mechanism'=>$layout['mechanism'],'label'=>$layout['label']),'seo'=>array('rankMath'=>defined('RANK_MATH_VERSION'),'title'=>true,'description'=>true,'focusKeyword'=>true)),'categories'=>array('discovery'=>true,'assignment'=>true,'creation'=>current_user_can('manage_categories'),'rankMathPrimary'=>defined('RANK_MATH_VERSION')));}));
 register_rest_route('agency-os/v1','/authors',array('methods'=>'GET','permission_callback'=>$permission,'callback'=>function(){return aos_authors();}));
 register_rest_route('agency-os/v1','/categories',array('methods'=>'GET','permission_callback'=>$permission,'callback'=>function(){return aos_categories();}));
 register_rest_route('agency-os/v1','/snapshot',array('methods'=>'POST','permission_callback'=>$permission,'callback'=>function($r){return aos_snapshot($r['url']);}));
 register_rest_route('agency-os/v1','/jobs/(?P<id>[a-f0-9-]+)',array('methods'=>'GET','permission_callback'=>$permission,'callback'=>function($r){$j=get_option('aos_job_'.$r['id']);if(!$j)return new WP_Error('unknown','No recorded operation; do not assume the change was applied',array('status'=>404));if(isset($j['cleanup']))return aos_cleanup_status($j);return aos_receipt($j);}));
 register_rest_route('agency-os/v1','/native-page-draft',array('methods'=>'POST','permission_callback'=>$permission,'callback'=>function($r){return aos_apply($r,true);}));
 register_rest_route('agency-os/v1','/native-page-render',array('methods'=>'POST','permission_callback'=>$permission,'callback'=>'aos_native_page_render'));
 register_rest_route('agency-os/v1','/preflight',array('methods'=>'POST','permission_callback'=>$permission,'callback'=>'aos_preflight'));
 register_rest_route('agency-os/v1','/apply',array('methods'=>'POST','permission_callback'=>$permission,'callback'=>'aos_apply'));
 register_rest_route('agency-os/v1','/rank-math-score',array('methods'=>'POST','permission_callback'=>$permission,'callback'=>'aos_rank_math_score'));
});
// A fixed accessibility repair for Atlas-owned native pages only. It carries
// no author-supplied CSS and does not change the site's global theme settings.
function aos_native_page_focus_css($id){
 $id=(int)$id;if(!$id||get_post_type($id)!=='page'||get_post_meta($id,'_et_pb_use_builder',true)!=='on')return '';
 $jobId=get_post_meta($id,'_aos_job',true);$job=is_string($jobId)?get_option('aos_job_'.$jobId):false;
 if(!$job||(int)($job['postId']??0)!==$id||($job['nativePage']['contractVersion']??0)!==1||($job['nativePage']['builder']??'')!=='divi')return '';
 return '#main-content a:focus-visible,#main-content button:focus-visible,#main-content input:focus-visible,#main-content select:focus-visible,#main-content textarea:focus-visible,#main-content [tabindex]:focus-visible{outline:3px solid #1263db!important;outline-offset:4px;box-shadow:0 0 0 7px #fff!important}';
}
add_action('wp_head',function(){if(is_singular('page')){$css=aos_native_page_focus_css(get_queried_object_id());if($css!=='')echo '<style id="aos-native-page-focus">'.$css.'</style>';}},101);
add_action('wp_head',function(){if(is_singular()){$schema=get_post_meta(get_queried_object_id(),'_aos_schema',true);if(is_array($schema))echo '<script type="application/ld+json">'.wp_json_encode($schema,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script>';}});
// 0.5.4 — WordPress's own third-party plugin-update mechanism (the "Update
// URI" header above, matched to this filter's host). Publisher updates are
// explicitly opted into WordPress's background updater for this plugin only.
// WordPress still owns scheduling, permissions, maintenance mode, recovery
// and any global file-modification policy. The checksum gate below refuses a
// missing, corrupted or tampered package before installation.
function aos_fetch_manifest(){$cached=get_site_transient('aos_manifest_cache');if(is_array($cached))return $cached;$response=wp_remote_get(AOS_MANIFEST_URL,array('timeout'=>10));if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)return null;$m=json_decode(wp_remote_retrieve_body($response),true);if(!is_array($m)||empty($m['version'])||empty($m['zipUrl']))return null;set_site_transient('aos_manifest_cache',$m,HOUR_IN_SECONDS);return $m;}
// 0.5.6 — WordPress validates version before normalizing new_version.
// Supply both so native update discovery accepts this response.
add_filter('update_plugins_github.com',function($update,$plugin_data,$plugin_file){
 if(plugin_basename(__FILE__)!==$plugin_file)return $update;
 $m=aos_fetch_manifest();if(!$m||version_compare((string)$m['version'],AOS_PLUGIN_VERSION,'<='))return $update;
 return array('id'=>AOS_MANIFEST_URL,'slug'=>'agency-os-publisher','plugin'=>$plugin_file,'version'=>(string)$m['version'],'new_version'=>(string)$m['version'],'url'=>(string)($m['detailsUrl']??''),'package'=>(string)$m['zipUrl'],'requires'=>(string)($m['requiresWordPress']??''),'requires_php'=>(string)($m['requiresPhp']??''),'tested'=>(string)($m['testedWordPress']??''),'autoupdate'=>true);
},10,3);
function aos_is_own_update_item($item){
 if(!is_object($item))return false;
 $plugin=(string)($item->plugin??'');$slug=(string)($item->slug??'');$id=(string)($item->id??'');
 return $plugin===plugin_basename(__FILE__)||$slug==='agency-os-publisher'||$id===AOS_MANIFEST_URL;
}
add_filter('auto_update_plugin',function($update,$item){return aos_is_own_update_item($item)?true:$update;},10,2);
// 0.5.0.1 — the manifest's SHA-256 verified before WordPress installs this
// plugin's own update, fail-closed. upgrader_pre_download lets a plugin
// perform (and vouch for) its own package download; returning the verified
// local file path here is WordPress's documented way to do that, so this is
// still WordPress's native updater, not a replacement for it. Scoped to this
// plugin's own package only — every other plugin and core update returns
// $reply=false unchanged and downloads exactly as WordPress always has.
add_filter('upgrader_pre_download',function($reply,$package,$upgrader,$hook_extra=array()){
 $plugin=is_array($hook_extra)?($hook_extra['plugin']??''):'';
 if($plugin!==plugin_basename(__FILE__))return $reply;
 $m=aos_fetch_manifest();
 if(!$m||empty($m['sha256'])||!preg_match('/^[a-f0-9]{64}$/i',(string)$m['sha256']))return new WP_Error('aos_checksum_missing','Agency OS Publisher update refused: no checksum was published for this release, so it cannot be verified.');
 $file=download_url($package,300);
 if(is_wp_error($file))return $file;
 $actual=hash_file('sha256',$file);
 if(!$actual||!hash_equals(strtolower((string)$m['sha256']),strtolower($actual))){@unlink($file);return new WP_Error('aos_checksum_mismatch','Agency OS Publisher update refused: the downloaded package does not match its published checksum. This may be a corrupted download or a tampered release; it was not installed.');}
 return $file;
},10,4);
add_filter('plugins_api',function($result,$action,$args){
 if($action!=='plugin_information'||($args->slug??'')!=='agency-os-publisher')return $result;
 $m=aos_fetch_manifest();if(!$m)return $result;
 return (object)array('name'=>'Agency OS Approved Publisher','slug'=>'agency-os-publisher','version'=>(string)($m['version']??AOS_PLUGIN_VERSION),'download_link'=>(string)($m['zipUrl']??''),'requires'=>(string)($m['requiresWordPress']??''),'requires_php'=>(string)($m['requiresPhp']??''),'tested'=>(string)($m['testedWordPress']??''),'sections'=>array('description'=>(string)($m['notes']??'Applies explicitly approved website changes and verifies their saved values.')));
},10,3);


// 0.5.8 — owner-authorized, bounded layout repair recipes. No arbitrary CSS, scripts or content edits.
function aos_layout_recipes(){return json_decode(<<<'AOS_LAYOUT_RULES'
{"article_fit":"@media(max-width:980px){body.single-post .entry-title,body.single-post .entry-content,body.single-post .et_pb_post_content{min-width:0;overflow-wrap:anywhere}body.single-post .entry-content :is(img,video,iframe,pre,table),body.single-post .et_pb_post_content :is(img,video,iframe,pre,table){max-width:100%!important;box-sizing:border-box}body.single-post .entry-content :is(pre,table),body.single-post .et_pb_post_content :is(pre,table){overflow-x:auto;display:block}}","divi_footer":"@media(max-width:980px){.et-l--footer .et_pb_column_2_tb_footer{transform:none!important;max-width:100%}.et-l--footer .et_pb_column_2_tb_footer .et_pb_module{transform:none!important;margin:12px auto!important;max-width:100%}.et-l--footer .et_pb_column_2_tb_footer .et_pb_image{width:220px}}","divi_header":"@media(max-width:980px){body.single-post .et-l--header .et_pb_text_1_tb_header{margin-bottom:16px!important}}","flow_widgets":"@media(max-width:980px){#gt_float_wrapper{position:static!important;display:flex;justify-content:center;margin:16px auto;max-width:calc(100% - 32px)}chat-widget{display:block;position:relative;transform:translateZ(0);height:80px;z-index:1000000;max-width:100%;--chat-widget-width:min(340px,calc(100vw - 40px))!important}chat-widget[data-active=\"false\"]{overflow:hidden}}","divi_header_image":"@media(max-width:980px){.et-l--header .et_pb_image{transform:none!important;max-width:100%!important;left:auto!important;right:auto!important;margin-left:auto!important;margin-right:auto!important}.et-l--header .et_pb_image .et_pb_image_wrap{transform:none!important;max-width:100%!important}.et-l--header .et_pb_image img{max-width:100%!important;height:auto!important}}","flow_scroll_top":"@media(max-width:980px){.et_pb_scroll_top.et-pb-icon{position:relative!important;inset:auto!important;display:block;margin:16px 16px 16px auto!important;width:40px;box-sizing:border-box;text-align:center}}","flow_monarch":"@media(max-width:980px){.et_social_mobile.et_social_fadein{position:relative!important;inset:auto!important;transform:none!important;max-width:100%!important;box-sizing:border-box}}","flow_divi_nav":"@media(max-width:980px){.et-l--header .et_pb_section--with-menu.et_pb_sticky--top{position:relative!important;top:auto!important;bottom:auto!important;transform:none!important;max-width:100%!important}}"}
AOS_LAYOUT_RULES
,true);}
function aos_layout_values(){ $s=get_option('aos_layout_repairs_v1',array());return is_array($s)?$s:array(); }
function aos_layout_hash($s){return hash('sha256',wp_json_encode($s));}
function aos_layout_state(){
 $s=aos_layout_values();$templates=array();foreach(array('post','page') as $type){$posts=get_posts(array('post_type'=>$type,'post_status'=>'publish','numberposts'=>1));if($posts)$templates[$type]=get_permalink($posts[0]->ID);}
 return array('version'=>1,'stateHash'=>aos_layout_hash($s),'recipes'=>array_values($s),'supportedRecipes'=>array_keys(aos_layout_recipes()),'templates'=>$templates);
}
function aos_layout_purge(){
 // Existing caches only. Never activate/reinstall an optimizer or change cache/security settings.
 do_action('litespeed_purge_all','Atlas verified mobile layout repair');
 if(function_exists('sg_cachepress_purge_cache'))sg_cachepress_purge_cache();
}
function aos_layout_repair($r){
 $in=$r->get_json_params();if(($in['version']??null)!==1)return new WP_Error('layout_version','Unsupported layout repair contract',array('status'=>400));
 $lock='aos_layout_repair_lock';if(!add_option($lock,time(),'','no'))return new WP_Error('layout_busy','Another layout repair is running. Retry after it finishes.',array('status'=>409));
 try{
  $before=aos_layout_values();if(!hash_equals(aos_layout_hash($before),(string)($in['beforeHash']??'')))return new WP_Error('layout_changed','Layout repair state changed; read it again before changing it.',array('status'=>409));
  if(isset($in['restoreId'])){
   $id=(string)$in['restoreId'];if(!preg_match('/^[a-f0-9-]{36}$/',$id))return new WP_Error('layout_restore','Invalid layout restore identity',array('status'=>400));
   $backup=get_option('aos_layout_backup_'.$id);if(!is_array($backup)||!hash_equals($backup['afterHash'],aos_layout_hash($before)))return new WP_Error('layout_restore','The repair backup no longer matches this website. No restore was applied.',array('status'=>409));
   update_option('aos_layout_repairs_v1',$backup['before'],false);aos_layout_purge();return array('restored'=>true,'stateHash'=>aos_layout_hash(aos_layout_values()));
  }
  $requested=$in['recipes']??null;if(!is_array($requested)||!count($requested)||count($requested)>8)return new WP_Error('layout_recipe','Select supported layout recipes',array('status'=>400));
  $known=aos_layout_recipes();foreach($requested as $id)if(!is_string($id)||!isset($known[$id]))return new WP_Error('layout_recipe','Unknown layout repair; no code was applied.',array('status'=>400));
  $after=array_values(array_unique(array_merge($before,$requested)));sort($after);$id=wp_generate_uuid4();$afterHash=aos_layout_hash($after);
  if(!add_option('aos_layout_backup_'.$id,array('before'=>$before,'afterHash'=>$afterHash,'at'=>gmdate('c'),'reason'=>sanitize_text_field((string)($in['reason']??''))),'','no'))return new WP_Error('layout_backup','Could not save the previous layout rules. Nothing was changed.',array('status'=>500));
  update_option('aos_layout_repairs_v1',$after,false);if(aos_layout_hash(aos_layout_values())!==$afterHash)return new WP_Error('layout_save','The layout repair could not be confirmed.',array('status'=>500));
  aos_layout_purge();return array('repairId'=>$id,'stateHash'=>$afterHash,'recipes'=>$after,'verified'=>true);
 }finally{delete_option($lock);}
}

// Read-only rendered shell for authorized layout checks when a host's public index is unavailable.
// Only an existing published post may be rendered. No draft, revision, option or post is written.
function aos_layout_preview($r){
 $url=(string)($r['url']??'');if(!aos_local_url($url))return new WP_Error('site','Choose a template on this website',array('status'=>400));
 $id=url_to_postid($url);$post=$id?get_post($id):null;
 if(!$post||$post->post_status!=='publish'||!in_array($post->post_type,array('post','page'),true)||!current_user_can('edit_post',$id))return new WP_Error('layout_template','An editable published template is required',array('status'=>409));
 $saved=array();foreach(array('wp_query','wp_the_query','post') as $name)$saved[$name]=$GLOBALS[$name]??null;
 $user=get_current_user_id();$level=ob_get_level();
 try{
  // Render the visitor view, never an administrator toolbar or private user data.
  wp_set_current_user(0);$query=new WP_Query($post->post_type==='page'?array('page_id'=>$id,'post_type'=>'page','post_status'=>'publish'):array('p'=>$id,'post_type'=>'post','post_status'=>'publish'));
  $GLOBALS['wp_query']=$query;$GLOBALS['wp_the_query']=$query;$GLOBALS['post']=$post;setup_postdata($post);
  $file=$post->post_type==='page'?get_page_template():get_single_template();if(!$file)return new WP_Error('layout_template','The theme template could not be rendered',array('status'=>409));
  ob_start();include $file;$html=ob_get_clean();if(!is_string($html)||strlen($html)>3000000||strpos($html,'<html')===false)return new WP_Error('layout_template','The rendered template was incomplete',array('status'=>409));
  return array('version'=>1,'url'=>get_permalink($id),'html'=>$html,'stateHash'=>aos_layout_hash(aos_layout_values()));
 }finally{while(ob_get_level()>$level)ob_end_clean();foreach($saved as $name=>$value)$GLOBALS[$name]=$value;wp_set_current_user($user);}
}

add_action('wp_head',function(){ $known=aos_layout_recipes();$css='';foreach(aos_layout_values() as $id)if(isset($known[$id]))$css.=$known[$id];if($css!=='')echo '<style id="aos-mobile-layout-repairs">'.$css.'</style>'; },100);
add_action('rest_api_init',function(){
 register_rest_route('agency-os/v1','/layout-preview',array('methods'=>'POST','permission_callback'=>function(){return current_user_can('edit_posts');},'callback'=>'aos_layout_preview'));
 register_rest_route('agency-os/v1','/layout-state',array('methods'=>'GET','permission_callback'=>function(){return current_user_can('edit_posts');},'callback'=>'aos_layout_state'));
 register_rest_route('agency-os/v1','/layout-repairs',array('methods'=>'POST','permission_callback'=>function(){return current_user_can('edit_theme_options');},'callback'=>'aos_layout_repair'));
});
