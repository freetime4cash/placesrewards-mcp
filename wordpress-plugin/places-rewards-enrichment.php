<?php
/**
 * Plugin Name: Places Rewards Prospect Enrichment
 * Description: Server-side prospect intake, enrichment, geocoding, scoring, persistence, ranking, and report generation for Places Rewards.
 * Version: 1.7.4
 */
if (!defined('ABSPATH')) exit;

final class PR_Prospect_Enrichment {
 const NS='places-rewards/v1';
 const CPT='pr_prospect';
 public static function init(){
  add_action('init',[__CLASS__,'cpt']);
  add_action('rest_api_init',[__CLASS__,'routes']);
  add_shortcode('places_rewards_prospect_intake',[__CLASS__,'shortcode']);
  add_action('init',[__CLASS__,'register_priority_queue'],99);
  add_action('admin_init',[__CLASS__,'repair_legacy_records']);
  add_action('admin_init',[__CLASS__,'upgrade_130_page']);
  add_action('init',[__CLASS__,'upgrade_140'],101);
  add_action('init',[__CLASS__,'upgrade_150'],102);
  add_action('init',[__CLASS__,'upgrade_152'],103);
  add_action('init',[__CLASS__,'upgrade_153'],104);
  add_action('init',[__CLASS__,'upgrade_160'],105);
  add_shortcode('places_rewards_revenue_engine_workspace',[__CLASS__,'revenue_workspace']);
  add_shortcode('places_rewards_prospecting_workspace',[__CLASS__,'prospecting_workspace']);
  add_action('template_redirect',[__CLASS__,'render_totalcontest_demo_preview'],1);
  add_action('template_redirect',[__CLASS__,'no_cache_acquisition']);
  add_filter('cron_schedules',[__CLASS__,'cron_schedules']);
  add_action('init',[__CLASS__,'upgrade_174_materialize_baseline'],119);
  add_action('init',[__CLASS__,'ensure_autopilot_schedule'],120);
  add_action('prx_autopilot_tick',[__CLASS__,'autopilot_tick']);
 }
 public static function cpt(){register_post_type(self::CPT,['label'=>'PR Prospects','public'=>false,'show_ui'=>true,'show_in_rest'=>true,'supports'=>['title','editor','custom-fields']]);}
 public static function routes(){
  register_rest_route(self::NS,'/prospects/analyze',['methods'=>'POST','callback'=>[__CLASS__,'analyze'],'permission_callback'=>function(){return current_user_can('edit_pages');},'args'=>['business_input'=>['required'=>true,'sanitize_callback'=>'sanitize_text_field']]]);
  register_rest_route(self::NS,'/prospects/stage',['methods'=>'POST','callback'=>[__CLASS__,'update_stage'],'permission_callback'=>function(){return current_user_can('edit_pages');},'args'=>['prospect_id'=>['required'=>true,'sanitize_callback'=>'absint'],'stage'=>['required'=>true,'sanitize_callback'=>'sanitize_key']]]);
  register_rest_route(self::NS,'/prospects/sales-status',['methods'=>'POST','callback'=>[__CLASS__,'update_sales_status'],'permission_callback'=>function(){return current_user_can('edit_pages');},'args'=>['prospect_id'=>['required'=>true,'sanitize_callback'=>'absint'],'status'=>['required'=>true,'sanitize_callback'=>'sanitize_key'],'followup_date'=>['required'=>false,'sanitize_callback'=>'sanitize_text_field']]]);
  register_rest_route(self::NS,'/autopilot/totalcontest/inspect',['methods'=>'GET','callback'=>[__CLASS__,'inspect_totalcontest'],'permission_callback'=>[__CLASS__,'inspect_permission']]);
  register_rest_route(self::NS,'/autopilot/demo/build',['methods'=>'POST','callback'=>[__CLASS__,'build_autopilot_demo'],'permission_callback'=>[__CLASS__,'inspect_permission'],'args'=>['prospect_id'=>['required'=>true,'sanitize_callback'=>'absint']]]);
  register_rest_route(self::NS,'/autopilot/demo/status/(?P<prospect_id>\d+)',['methods'=>'GET','callback'=>[__CLASS__,'autopilot_demo_status'],'permission_callback'=>[__CLASS__,'inspect_permission']]);
  register_rest_route(self::NS,'/autopilot/queue',['methods'=>'GET','callback'=>[__CLASS__,'autopilot_queue'],'permission_callback'=>[__CLASS__,'inspect_permission']]);
  register_rest_route(self::NS,'/autopilot/demo/deployed',['methods'=>'POST','callback'=>[__CLASS__,'autopilot_demo_deployed'],'permission_callback'=>[__CLASS__,'inspect_permission'],'args'=>['prospect_id'=>['required'=>true,'sanitize_callback'=>'absint'],'demo_url'=>['required'=>true,'sanitize_callback'=>'esc_url_raw']]]);
 }

 public static function repair_legacy_records(){
  if(get_option('pr_enrichment_repair_120')) return;
  $all=get_posts(['post_type'=>self::CPT,'post_status'=>['publish','private'],'numberposts'=>-1]);
  foreach($all as $x){
   if($x->post_status!=='private') wp_update_post(['ID'=>$x->ID,'post_status'=>'private']);
   $score=(int)get_post_meta($x->ID,'_pr_score',true); if(!$score) $score=(int)get_post_meta($x->ID,'_pr_priority_score',true);
   if($score){ $r=self::rank($x->ID,$score); update_post_meta($x->ID,'_pr_rank',$r['rank']); update_post_meta($x->ID,'_pr_total',$r['total']); }
  }
  update_option('pr_enrichment_repair_120',current_time('mysql'),false);
 }

 public static function register_priority_queue(){ add_shortcode('places_rewards_priority_queue',[__CLASS__,'priority_queue']); }
 public static function cron_schedules($schedules){
  if(!isset($schedules['prx_fifteen_minutes']))$schedules['prx_fifteen_minutes']=['interval'=>900,'display'=>'Every 15 minutes'];
  return $schedules;
 }
 public static function ensure_autopilot_schedule(){
  if(!wp_next_scheduled('prx_autopilot_tick'))wp_schedule_event(time()+60,'prx_fifteen_minutes','prx_autopilot_tick');
 }
 public static function upgrade_174_materialize_baseline(){
  if(get_option('pr_enrichment_upgrade_174'))return;
  $existing=get_posts(['post_type'=>self::CPT,'post_status'=>'any','numberposts'=>-1]);
  $by_title=[];foreach($existing as $x)$by_title[strtolower(trim($x->post_title))]=$x->ID;
  $base=self::baseline();$total=count($base);
  foreach($base as $i=>$b){
   [$name,$score,$category,$city,$path,$domain]=$b;
   $key=strtolower(trim($name));$id=(int)($by_title[$key]??0);
   $website=$domain?('https://'.$domain):'';
   if(!$id){
    $p=['input'=>$website?:$name,'name'=>$name,'website'=>$website,'phone'=>'','address'=>$city.', Ohio','category'=>$category,'description'=>'','lat'=>null,'lng'=>null,'source'=>'baseline_seed_unverified','enriched_at'=>current_time('mysql')];
    $p['dedupe']=hash('sha256',strtolower(trim(($website?:($city.'|'.$name)).'|'.$name)));
    $p['score']=(int)$score;$p['dimensions']=array_combine(['revenue_potential','retention_opportunity','referral_opportunity','rewards_fit','gamification_fit','local_discovery_fit','acquisition_feasibility','implementation_ease','account_value'],self::category_scores($category));
    $id=self::upsert($p);$by_title[$key]=$id;
   }else{
    if(!get_post_meta($id,'_pr_score',true))update_post_meta($id,'_pr_score',(int)$score);
    if(!get_post_meta($id,'_pr_category',true))update_post_meta($id,'_pr_category',$category);
    if(!get_post_meta($id,'_pr_website',true)&&$website)update_post_meta($id,'_pr_website',$website);
    if(!get_post_meta($id,'_pr_source',true))update_post_meta($id,'_pr_source','baseline_seed_unverified');
    if(!get_post_meta($id,'_pr_stage',true))update_post_meta($id,'_pr_stage','prospect');
   }
   if(!get_post_meta($id,'_pr_rank',true))update_post_meta($id,'_pr_rank',$i+1);
   update_post_meta($id,'_pr_total',$total);
  }
  update_option('pr_enrichment_upgrade_174',current_time('mysql'),false);
 }
 public static function native_demo_assets($d){
  $secret_path='/home/placevle/.pr_demo_bridge_secret';
  if(!is_readable($secret_path))return [];
  $secret=trim((string)file_get_contents($secret_path));if($secret==='')return [];
  $url=add_query_arg(['business'=>$d['name'],'slug'=>sanitize_title($d['name']),'category'=>$d['category']??''],'https://app.placesrewards.com/demo/internal/native-build');
  $r=wp_remote_get($url,[
   'timeout'=>30,
   'headers'=>['X-PR-DEMO-BRIDGE'=>$secret,'Accept'=>'application/json'],
  ]);
  if(is_wp_error($r)||wp_remote_retrieve_response_code($r)!==200)return [];
  $j=json_decode((string)wp_remote_retrieve_body($r),true);
  return is_array($j)&&!empty($j['native'])&&is_array($j['native'])?$j['native']:[];
 }
 public static function persist_laravel_manifest($manifest){
  $slug=sanitize_title((string)($manifest['slug']??''));if($slug==='')return false;
  $dir='/home/placevle/app.placesrewards.com/storage/app';
  if(!is_dir($dir)||!is_writable($dir))return false;
  $path=$dir.'/demo-'.$slug.'.json';
  return false!==file_put_contents($path,wp_json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX);
 }
 public static function autopilot_tick(){
  self::upgrade_174_materialize_baseline();
  $all=get_posts(['post_type'=>self::CPT,'post_status'=>'private','numberposts'=>-1]);$rows=[];
  foreach($all as $x){
   $d=self::prospect_data($x->ID);if(!$d||empty($d['score']))continue;
   if((string)get_post_meta($x->ID,'_pr_autopilot_demo_status',true)==='deployed')continue;
   $rows[]=$d;
  }
  usort($rows,function($a,$b){return $b['score']<=>$a['score'] ?: $a['rank']<=>$b['rank'];});
  foreach(array_slice($rows,0,2) as $d){
   $req=new WP_REST_Request('POST','/'.self::NS.'/autopilot/demo/build');$req->set_param('prospect_id',(int)$d['id']);
   self::build_autopilot_demo($req);
  }
 }
 static function baseline(){
  return [
   ['17 River Grille',88,'Restaurants & Food','Chagrin Falls','/prospect-17-river-grille/','17rivergrille.com'],
   ["JoJo's Bar",86,'Restaurants & Food','Chagrin Falls','/prospect-jojo-s-bar/','jojosbar.com'],
   ["Lola's Bistro",85,'Restaurants & Food','Chagrin Falls','/prospect-lola-s-bistro/','lolachagrin.com'],
   ['Manhattan Deli Bar & Grille',84,'Restaurants & Food','Willoughby','/prospect-manhattan-deli-bar-grille/','mymanhattandeli.com'],
   ['Evergreen Bakery',83,'Coffee & Cafes','Chagrin Falls','/prospect-evergreen-bakery/','evergreen-bakery.com'],
   ['Heartwood Coffee Roasters',82,'Coffee & Cafes','Chagrin Falls','/prospect-heartwood-coffee-roasters/','heartwoodroastery.com'],
   ['Tame Rabbit Specialty Coffee & Roaster',81,'Coffee & Cafes','Chagrin Falls','/prospect-tame-rabbit-specialty-coffee-roaster/','tamerabbit.com'],
   ["Bruegger's Bagels",80,'Restaurants & Food','Willoughby','/prospect-bruegger-s-bagels/','locations.brueggers.com'],
   ['SHED Boutique and Wellness',78,'Retail & Shopping','Chagrin Falls','/prospect-shed-boutique-and-wellness/','shedchagrin.com'],
   ['Base Boutique',77,'Retail & Shopping','Chagrin Falls','/prospect-base-boutique/','shopbaseboutique.com'],
   ['Fireside Book Shop',75,'Retail & Shopping','Chagrin Falls','/prospect-fireside-book-shop/','firesidebookshop.com'],
   ["It's So You",74,'Retail & Shopping','Willoughby','/prospect-it-s-so-you/','itssoyouboutique.com'],
   ['Fred Astaire Dance Studios - Willoughby',73,'Fitness & Activities','Willoughby','/prospect-fred-astaire-dance-studios-willoughby/','fredastaire.com'],
   ['Karate For Kids',72,'Fitness & Activities','Willoughby','/prospect-karate-for-kids/','willoughbykarate.com'],
   ['Meadowlands Veterinary Center',70,'Pet Services','Willoughby','/prospect-meadowlands-veterinary-center/','meadowlandsvet.com'],
   ['We Buy CLE',66,'Professional Services','Willoughby','/prospect-we-buy-cle/','webuycle.com'],
   ['Howard Hanna - Willoughby',64,'Professional Services','Willoughby','/prospect-howard-hanna-willoughby/','howardhanna.com'],
   ['The UPS Store',61,'Professional Services','Willoughby','/prospect-the-ups-store/','locations.theupsstore.com'],
   ['Two and Company',59,'Retail & Shopping','Chagrin Falls','/prospect-two-and-company/','twoandcompany.org'],
   ['Subway',54,'Restaurants & Food','Willoughby','/prospect-subway/','']
  ];
 }
 public static function upgrade_140(){
  if(get_option('pr_enrichment_upgrade_140')) return;
  $p=['input'=>'https://smokeshopgarfieldheights.com/','name'=>'Blaze Exotics','website'=>'https://smokeshopgarfieldheights.com/','phone'=>'(216) 616-8594','address'=>'5350 Turney Rd #3, Garfield Heights, OH 44125','category'=>'Retail & Shopping / Smoke Shop','description'=>'Locally owned smoke, vape, cigar, hookah, novelty, snack and beverage retailer in Garfield Heights, Ohio.','lat'=>null,'lng'=>null,'source'=>'verified public business website','enriched_at'=>current_time('mysql')];
  $p['dedupe']=hash('sha256',strtolower(trim($p['website'].'|'.$p['name'])));
  $sc=self::score($p); $p['score']=$sc['score']; $p['dimensions']=$sc['dimensions'];
  $id=self::upsert($p); $r=self::rank($id,$sc['score']);
  update_post_meta($id,'_pr_rank',$r['rank']); update_post_meta($id,'_pr_total',$r['total']); update_post_meta($id,'_pr_report',wp_json_encode(self::report($p,$sc,$r)));
  $page=get_post(971);
  if($page && !str_contains($page->post_content,'/revenue-engine-prospecting/')){
   $old='<a class="btn dark" href="/marketing-revenue-system/">Open Business Intake</a>';
   $new=$old.'<a class="btn dark" href="/revenue-engine-prospecting/">Open Prospecting Workflow →</a>';
   wp_update_post(['ID'=>971,'post_content'=>str_replace($old,$new,$page->post_content)]);
  }
  update_option('pr_enrichment_upgrade_140',current_time('mysql'),false);
 }

 static function tier($s){return $s>=85?'Immediate':($s>=70?'Demo Priority':($s>=55?'Nurture':($s>=40?'Monitor':'Low')));}
 public static function priority_queue(){
  if(!current_user_can('edit_pages')) return '<div class="prq-note">Sign in with an acquisition/admin account to view the private prospect queue.</div>';
  $rows=[];$hosts=[];
  foreach(self::baseline() as $b){$rows[]=['name'=>$b[0],'score'=>$b[1],'category'=>$b[2],'location'=>$b[3],'url'=>$b[4],'host'=>$b[5],'dynamic'=>false,'id'=>0];if($b[5])$hosts[$b[5]]=1;}
  $all=get_posts(['post_type'=>self::CPT,'post_status'=>'private','numberposts'=>-1]);
  foreach($all as $x){
   $d=self::prospect_data($x->ID);if(!$d||!$d['score'])continue;
   $website=$d['website'];$host=strtolower((string)wp_parse_url($website,PHP_URL_HOST));$host=preg_replace('/^www\./','',$host);
   $dup=false;foreach(array_keys($hosts) as $h){$hh=preg_replace('/^www\./','',strtolower($h));if($host && ($host===$hh || str_ends_with($host,'.'.$hh) || str_ends_with($hh,'.'.$host))){$dup=true;break;}}
   if($dup)continue;
   $loc=$d['address']?:'Enriched prospect';
   $rows[]=['name'=>$d['name'],'score'=>$d['score'],'category'=>$d['category']?:'Local Business','location'=>$loc,'url'=>'/marketing-revenue-system/?prospect_id='.$x->ID.'#prospect-'.$x->ID,'host'=>$host,'dynamic'=>true,'id'=>$x->ID];
  }
  usort($rows,function($a,$b){return $b['score']<=>$a['score'] ?: strcasecmp($a['name'],$b['name']);});
  $focus=isset($_GET['prospect'])?(int)$_GET['prospect']:0;
  $revurl=$focus?'/revenue-engine/?prospect_id='.$focus:'/revenue-engine/';
  $prosurl=$focus?'/revenue-engine-prospecting/?prospect_id='.$focus:'/revenue-engine-prospecting/';
  ob_start(); ?>
  <div class="prq"><style>.prq{font-family:Arial,sans-serif}.prq-head{display:flex;justify-content:space-between;align-items:end;gap:15px;margin:20px 0 12px}.prq-head h2{margin:0}.prq-sub{color:#64748b}.prq-row{display:grid;grid-template-columns:70px minmax(260px,2fr) 110px 150px 180px;gap:14px;align-items:center;padding:15px 18px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;margin:9px 0}.prq-row.focus{outline:3px solid #6c1cff;background:#faf7ff}.prq-rank,.prq-score{font-size:21px;font-weight:900}.prq-name{font-weight:900}.prq-meta{font-size:13px;color:#64748b;margin-top:4px}.prq-tier{font-weight:800}.prq-btn{display:block;text-align:center;background:#111827;color:#fff!important;text-decoration:none;padding:11px 12px;border-radius:9px;font-weight:800;white-space:normal}.prq-badge{display:inline-block;font-size:11px;background:#ede9fe;color:#5b21b6;border-radius:999px;padding:4px 7px;margin-left:6px}.prq-note{background:#fff7ed;padding:13px;border-radius:10px;margin:12px 0}@media(max-width:850px){.prq-row{grid-template-columns:55px 1fr 90px}.prq-tier{grid-column:2}.prq-btn{grid-column:2/4}}</style>
   <div class="prq-head"><div><h2>Unified Acquisition Priority Queue</h2><div class="prq-sub">Established prospects and newly analyzed businesses in one ranked list.</div></div><div><b><?php echo count($rows); ?> prospects</b><div style="margin-top:8px"><a class="prq-btn" href="<?php echo esc_url($revurl); ?>">Revenue Engine</a><a class="prq-btn" href="<?php echo esc_url($prosurl); ?>" style="margin-top:6px">Prospecting</a></div></div></div>
   <?php foreach($rows as $i=>$r): $rank=$i+1; $isfocus=$focus&&$r['id']===$focus; ?>
    <div id="<?php echo $r['id']?'prospect-'.$r['id']:'rank-'.$rank; ?>" class="prq-row<?php echo $isfocus?' focus':''; ?>">
     <div class="prq-rank">#<?php echo $rank; ?></div><div><div class="prq-name"><?php echo esc_html($r['name']); ?><?php if($r['dynamic']): ?><span class="prq-badge">LIVE INTAKE</span><?php endif; ?></div><div class="prq-meta"><?php echo esc_html($r['category'].' · '.$r['location']); ?></div></div>
     <div class="prq-score"><?php echo (int)$r['score']; ?>/100</div><div class="prq-tier"><?php echo esc_html(self::tier($r['score'])); ?></div><a class="prq-btn" href="<?php echo esc_url($r['url']); ?>">Open Prospect Action Center</a>
    </div>
   <?php endforeach; ?>
  </div><?php return ob_get_clean();
 }


 public static function upgrade_130_page(){
  if(get_option('pr_enrichment_page_130'))return;
  $p=get_post(1061);if($p){$content='<div id="prtop"><style>#prtop{font-family:Arial,sans-serif;max-width:1220px;margin:auto;color:#111827}#prtop *{box-sizing:border-box}.hero{background:linear-gradient(135deg,#111827,#312e81);color:#fff;padding:30px;border-radius:20px}.hero h1,.hero p{color:#fff!important}.btn{display:inline-block;background:#6c1cff;color:#fff!important;text-decoration:none;padding:11px 14px;border-radius:9px;font-weight:800;margin:3px}.note{background:#fff7ed;padding:13px;border-radius:10px;margin:14px 0}</style><div class="hero"><h1>Top Priority Business List</h1><p>One acquisition queue for established prospects and every business analyzed through the Launch Center. New prospects are inserted automatically using the same nine-factor model.</p><a class="btn" href="/marketing-revenue-system/">+ Analyze New Business</a></div>[places_rewards_priority_queue]<div class="note"><b>Scoring safeguard:</b> scores prioritize acquisition work. Unknown revenue, retention and referral facts remain assumptions until verified with the business.</div></div>';wp_update_post(['ID'=>1061,'post_content'=>$content,'post_excerpt'=>'Unified acquisition priority queue for established and newly enriched Places Rewards prospects.']);}
  update_option('pr_enrichment_page_130',current_time('mysql'),false);
 }

 static function safe_public_url($url){
  $u=wp_parse_url($url); if(!$u || !in_array(strtolower($u['scheme']??''),['http','https'],true) || empty($u['host'])) return false;
  $host=strtolower($u['host']); if($host==='localhost'||str_ends_with($host,'.local')) return false;
  $ips=gethostbynamel($host)?:[]; foreach($ips as $ip){ if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return false; }
  return true;
 }
 static function get_jsonld($html){
  $out=[]; if(preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',$html,$m)) foreach($m[1] as $j){$d=json_decode(html_entity_decode($j),true);if(!$d)continue;$nodes=isset($d['@graph'])?$d['@graph']:[ $d ];foreach($nodes as $n){$t=$n['@type']??'';$ts=is_array($t)?$t:[$t];if(array_intersect($ts,['LocalBusiness','Restaurant','Store','ProfessionalService','Organization','CafeOrCoffeeShop'])){$out=$n;break 2;}}} return $out;
 }
 static function normalize_address($a){if(is_array($a)) return trim(implode(', ',array_filter([$a['streetAddress']??'', $a['addressLocality']??'', $a['addressRegion']??'', $a['postalCode']??''])));return is_string($a)?$a:'';}
 static function geocode($q){
  $url=add_query_arg(['q'=>$q,'format'=>'jsonv2','limit'=>1,'addressdetails'=>1],'https://nominatim.openstreetmap.org/search');
  $r=wp_remote_get($url,['timeout'=>12,'user-agent'=>'PlacesRewards/1.0 ('.home_url('/').')']); if(is_wp_error($r))return [];$d=json_decode(wp_remote_retrieve_body($r),true);if(!$d||empty($d[0]))return [];return ['lat'=>(float)$d[0]['lat'],'lng'=>(float)$d[0]['lon'],'display_name'=>$d[0]['display_name']??'','type'=>$d[0]['type']??'','class'=>$d[0]['class']??''];
 }
 static function website($url){
  if(!self::safe_public_url($url)) return [];
  $r=wp_remote_get($url,['timeout'=>15,'redirection'=>5,'user-agent'=>'Mozilla/5.0 PlacesRewardsBot/1.0']);if(is_wp_error($r))return [];$html=wp_remote_retrieve_body($r);$ld=self::get_jsonld($html);$title='';if(preg_match('#<title[^>]*>(.*?)</title>#is',$html,$m))$title=trim(wp_strip_all_tags($m[1]));return ['url'=>$url,'title'=>$title,'jsonld'=>$ld,'phone'=>$ld['telephone']??'','name'=>$ld['name']??$title,'address'=>self::normalize_address($ld['address']??''),'category'=>$ld['@type']??'','description'=>wp_strip_all_tags($ld['description']??'')];
 }
 static function category_scores($cat){$c=strtolower($cat);if(str_contains($c,'restaurant')||str_contains($c,'food'))return [88,92,88,92,88,82,76,78,86];if(str_contains($c,'cafe')||str_contains($c,'coffee')||str_contains($c,'bakery'))return [80,94,88,94,92,86,82,86,78];if(str_contains($c,'retail')||str_contains($c,'store')||str_contains($c,'shop'))return [76,86,90,89,84,84,84,86,74];if(str_contains($c,'fitness')||str_contains($c,'dance')||str_contains($c,'karate'))return [80,84,90,84,84,76,78,76,80];if(str_contains($c,'pet')||str_contains($c,'veter'))return [82,88,86,82,68,74,80,78,78];if(str_contains($c,'professional')||str_contains($c,'real estate'))return [86,58,84,62,48,68,76,72,88];return [65,65,65,65,60,65,70,72,65];}
 static function score($p){
  $v=self::category_scores($p['category']??'');
  // Evidence adjusts feasibility/discovery, without treating unknown gaps as facts.
  if(!empty($p['website'])){$v[5]=min(100,$v[5]+5);$v[6]=min(100,$v[6]+5);} if(!empty($p['phone']))$v[6]=min(100,$v[6]+4); if(!empty($p['lat']))$v[5]=min(100,$v[5]+4);
  $w=[18,15,12,12,8,8,10,7,10];$sum=0;foreach($v as $i=>$n)$sum+=$n*$w[$i];return ['score'=>(int)round($sum/100),'dimensions'=>array_combine(['revenue_potential','retention_opportunity','referral_opportunity','rewards_fit','gamification_fit','local_discovery_fit','acquisition_feasibility','implementation_ease','account_value'],$v)];
 }
 static function upsert($p){
  $existing=get_posts(['post_type'=>self::CPT,'post_status'=>'any','meta_key'=>'_pr_dedupe','meta_value'=>$p['dedupe'],'numberposts'=>1]);$id=$existing?$existing[0]->ID:0;
  $arr=['post_type'=>self::CPT,'post_status'=>'private','post_title'=>$p['name']?:$p['input'],'post_content'=>wp_json_encode($p,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)];$id=$id?wp_update_post($arr+['ID'=>$id]):wp_insert_post($arr);
  foreach($p as $k=>$v)update_post_meta($id,'_pr_'.$k,is_scalar($v)?$v:wp_json_encode($v));
  if(!get_post_meta($id,'_pr_stage',true)) update_post_meta($id,'_pr_stage','prospect');
  return $id;
 }
 static function rank($id,$score){
  // Rank against the same visible baseline and normalized dynamic prospect set used by the queue.
  $base=self::baseline();$scores=[];$basehosts=[];
  foreach($base as $b){$scores[]=(int)$b[1];if(!empty($b[5]))$basehosts[]=preg_replace('/^www\./','',strtolower($b[5]));}
  $all=get_posts(['post_type'=>self::CPT,'post_status'=>['publish','private'],'numberposts'=>-1]);
  foreach($all as $x){
   if($x->ID==$id)continue;
   $n=(int)self::meta_first($x->ID,['_pr_score','_pr_priority_score'],0);if($n<=0)continue;
   $website=(string)self::meta_first($x->ID,['_pr_website','_pr_website_url'],'');
   $host=preg_replace('/^www\./','',strtolower((string)wp_parse_url($website,PHP_URL_HOST)));
   $dup=false;foreach($basehosts as $h){if($host&&($host===$h||str_ends_with($host,'.'.$h)||str_ends_with($h,'.'.$host))){$dup=true;break;}}
   if(!$dup)$scores[]=$n;
  }
  $rank=1;foreach($scores as $n){if($n>$score)$rank++;}
  return ['rank'=>$rank,'total'=>count($scores)+1];
 }
 static function report($p,$s,$r){return ['headline'=>$p['name'].' prospect opportunity','summary'=>sprintf('%s is ranked #%d of %d with a priority score of %d/100. Score dimensions are planning priorities derived from verified/enriched business attributes and category fit; unknown revenue facts remain assumptions until verified.',$p['name'],$r['rank'],$r['total'],$s['score']),'next_actions'=>['Verify decision-maker and contact path','Validate retention/referral/lead-recovery gaps','Replace economic assumptions with verified figures','Select only modules tied to verified gaps','Build personalized demo','Move through outreach and follow-up']];}
 public static function analyze(WP_REST_Request $req){
  $input=trim($req['business_input']);$isurl=(bool)filter_var($input,FILTER_VALIDATE_URL);if($isurl&&!self::safe_public_url($input))return new WP_Error('pr_unsafe_url','Only public HTTP/HTTPS business websites can be analyzed.',['status'=>400]);$web=$isurl?self::website($input):[];$address=$web['address']??(!$isurl?$input:'');$geo=$address?self::geocode($address):[];
  $name=$web['name']??'';if(!$name&&!$isurl&&$geo)$name=preg_replace('/,.*/','',$geo['display_name']);if(!$name)$name=$input;
  $p=['input'=>$input,'name'=>$name,'website'=>$isurl?$input:($web['url']??''),'phone'=>$web['phone']??'','address'=>$address,'category'=>$web['category']??($geo['type']??'Local Business'),'description'=>$web['description']??'','lat'=>$geo['lat']??null,'lng'=>$geo['lng']??null,'source'=>$isurl?'website+jsonld+nominatim':'address+nominatim','enriched_at'=>current_time('mysql')];
  $p['dedupe']=hash('sha256',strtolower(trim(($p['website']?:$p['address']).'|'.$p['name'])));$s=self::score($p);$p['score']=$s['score'];$p['dimensions']=$s['dimensions'];$id=self::upsert($p);$r=self::rank($id,$s['score']);update_post_meta($id,'_pr_rank',$r['rank']);update_post_meta($id,'_pr_total',$r['total']);$report=self::report($p,$s,$r);update_post_meta($id,'_pr_report',wp_json_encode($report));return rest_ensure_response(['ok'=>true,'prospect_id'=>$id,'prospect'=>$p,'score'=>$s,'ranking'=>$r,'report'=>$report]);
 }
 public static function meta_first($id,$keys,$default=''){
  foreach((array)$keys as $key){$v=get_post_meta($id,$key,true);if($v!==''&&$v!==null)return $v;}
  return $default;
 }
 public static function prospect_data($id){
  $x=get_post((int)$id); if(!$x||$x->post_type!==self::CPT)return [];
  $website=(string)self::meta_first($id,['_pr_website','_pr_website_url']);
  $phone=(string)self::meta_first($id,['_pr_phone']);
  if(!$phone){$phones=json_decode((string)self::meta_first($id,['_pr_phone_numbers'],'[]'),true);if(is_array($phones)&&$phones)$phone=(string)reset($phones);}
  $address=self::meta_first($id,['_pr_address'],'');
  if(is_string($address)&&in_array(substr(trim($address),0,1),['{','['],true)){$a=json_decode($address,true);if(is_array($a)){if(array_is_list($a))$address=implode(', ',array_filter(array_map('strval',$a)));else $address=implode(', ',array_filter([$a['street']??$a['streetAddress']??'', $a['city']??$a['addressLocality']??'', $a['state']??$a['addressRegion']??'', $a['postal_code']??$a['postalCode']??'']));}}
  $category=(string)self::meta_first($id,['_pr_category','_pr_category_profile'],'Local Business');
  $score=(int)self::meta_first($id,['_pr_score','_pr_priority_score'],0);
  $rank=(int)self::meta_first($id,['_pr_rank'],0);
  $total=(int)self::meta_first($id,['_pr_total'],0);
  $dims=json_decode((string)self::meta_first($id,['_pr_dimensions','_pr_factor_scores'],'{}'),true);if(!is_array($dims))$dims=[];
  $report=json_decode((string)self::meta_first($id,['_pr_report'],'{}'),true);if(!is_array($report))$report=[];
  $stage=(string)self::meta_first($id,['_pr_stage'],'prospect'); if(!in_array($stage,['prospect','demo','customer'],true))$stage='prospect';
  return ['post'=>$x,'id'=>(int)$id,'name'=>$x->post_title,'website'=>$website,'phone'=>$phone,'address'=>(string)$address,'category'=>$category,'score'=>$score,'rank'=>$rank,'total'=>$total,'dims'=>$dims,'report'=>$report,'stage'=>$stage];
 }
 public static function stage_label($stage){return $stage==='demo'?'Demo':($stage==='customer'?'Customer / Production':'Prospect');}
 public static function update_stage(WP_REST_Request $req){
  $id=absint($req['prospect_id']);$stage=sanitize_key($req['stage']);
  if(!in_array($stage,['prospect','demo','customer'],true))return new WP_Error('pr_invalid_stage','Invalid lifecycle stage.',['status'=>422]);
  $x=get_post($id);if(!$x||$x->post_type!==self::CPT)return new WP_Error('pr_missing_prospect','Prospect not found.',['status'=>404]);
  update_post_meta($id,'_pr_stage',$stage);update_post_meta($id,'_pr_stage_updated_at',current_time('mysql'));
  return rest_ensure_response(['ok'=>true,'prospect_id'=>$id,'stage'=>$stage,'label'=>self::stage_label($stage)]);
 }
 public static function tc_field($type,$name,$label,$required=false,$options=[],$placeholder=''){
  return [
   'uid'=>wp_generate_uuid4(),
   'type'=>$type,
   'name'=>sanitize_title_with_dashes($name),
   'label'=>$label,
   'placeholder'=>$placeholder,
   'default'=>'',
   'validations'=>['required'=>['enabled'=>(bool)$required]],
   'options'=>(array)$options,
   'attributes'=>[],
  ];
 }
 public static function tc_question_pack($d){
  $hay=strtolower(($d['name']??'').' '.($d['category']??'').' '.($d['website']??''));
  if(str_contains($hay,'smoke')||str_contains($hay,'vape')||str_contains($hay,'cigar')){
   return [
    self::tc_field('select','product_interest','Which products interest you most?',true,['cigars'=>'Cigars','vape'=>'Vape','accessories'=>'Accessories','glass'=>'Glass','wraps'=>'Wraps','novelty'=>'Novelty / specialty items','other'=>'Other']),
    self::tc_field('select','promotion_interest','Which offer would get your attention?',true,['bogo'=>'Buy one, get one','percent'=>'Percent off a favorite product','mystery'=>'Mystery reward','loyalty'=>'Loyalty reward','referral'=>'Referral reward']),
   ];
  }
  if(str_contains($hay,'restaurant')||str_contains($hay,'bakery')||str_contains($hay,'cafe')){
   return [
    self::tc_field('select','visit_style','How do you usually visit?',true,['dinein'=>'Dine in','takeout'=>'Takeout','delivery'=>'Delivery']),
    self::tc_field('textarea','favorite_menu','What menu item or type of food would bring you back?'),
   ];
  }
  if(str_contains($hay,'salon')||str_contains($hay,'spa')){
   return [
    self::tc_field('select','service_frequency','How often do you book services like ours?',true,['monthly'=>'Monthly','quarterly'=>'Every few months','occasionally'=>'Occasionally','first'=>'This would be my first time']),
    self::tc_field('textarea','desired_service','What service or package would you like to see more of?'),
   ];
  }
  return [
   self::tc_field('textarea','desired_product_service','What would you like this business to offer more of?'),
  ];
 }
 public static function tc_demo_settings($d,$kind){
  if(!function_exists('TotalContest'))return new WP_Error('pr_totalcontest_missing','TotalContest is not loaded.',['status'=>500]);
  $settings=(array)TotalContest('contests.defaults');
  $name=$d['name']?:'This Business';
  $settings['id']=0;
  $settings['contest']['submissions']['requiresApproval']=true;
  $settings['contest']['frequency']=['cookies'=>['enabled'=>true],'ip'=>['enabled'=>false],'user'=>['enabled'=>false],'count'=>1,'timeout'=>86400];
  $settings['notifications']['submission']['new']=false;
  $settings['menu']['default']='participate';
  $settings['pages']['default']='participate';
  $settings['design']['colors']['primary']='#6c1cff';
  $settings['design']['colors']['primaryDark']='#3f0c99';
  $settings['design']['colors']['secondary']='#10b981';
  $settings['design']['colors']['accent']='#f59e0b';
  if($kind==='selfie'){
   $settings['contest']['form']['fields']=[
    self::tc_field('text','first_name','First name',true,[],'Your first name'),
    self::tc_field('text','last_name','Last name',true,[],'Your last name'),
    self::tc_field('text','email','Email',true,[],'you@example.com'),
    self::tc_field('text','mobile','Mobile number',false,[],'Optional'),
    self::tc_field('image','selfie','Upload your visit selfie',true),
    self::tc_field('video','vlog_video','Optional: upload a short visit vlog',false),
    self::tc_field('textarea','caption','Tell us what you enjoyed about your visit',false),
    self::tc_field('text','favorite_item','What caught your attention today?',false),
    self::tc_field('select','visit_time','When did you visit?',true,['morning'=>'Morning','afternoon'=>'Afternoon','evening'=>'Evening']),
    self::tc_field('text','social_handle','Social handle',false,[],'Optional'),
    self::tc_field('checkbox','marketing_consent','Rewards and offers consent',false,['yes'=>'Yes, send me future rewards and offers']),
    self::tc_field('checkbox','content_permission','Photo/video permission',true,['yes'=>'Yes, the business may display my submitted contest content']),
    self::tc_field('checkbox','rules_acceptance','Contest rules',true,['yes'=>'I agree to the contest rules']),
   ];
   $settings['pages']['landing']['title']='Visit • Snap • Win';
   $settings['pages']['participate']['title']='Visit '.$name.', Take a Selfie or Short Vlog & Enter to Win';
   $settings['pages']['participate']['content']='<p><strong>DEMO CONTEST</strong> — This shows how Places Rewards can turn a targeted visit window into foot traffic, user-generated content and a permissioned customer lead.</p><p>Visit <strong>'.esc_html($name).'</strong>, take a selfie or short vlog during the promotion window, then submit it below.</p>';
   $settings['pages']['thankyou']['submission']['content']='Demo entry received. In production, Places Rewards can immediately continue the journey with a reward, referral prompt or comeback offer.';
  }else{
   $fields=[
    self::tc_field('text','first_name','First name',true),
    self::tc_field('text','email','Email',true,[],'you@example.com'),
    self::tc_field('select','discovery_source','How did you first hear about us?',true,['friend'=>'Friend or referral','social'=>'Social media','google'=>'Google','walkby'=>'Walking/driving by','event'=>'Event','other'=>'Other']),
    self::tc_field('select','visit_frequency','How often do you visit businesses like ours?',true,['first'=>'First time','monthly'=>'About monthly','two_three'=>'2–3 times a month','weekly'=>'Weekly','often'=>'More often']),
    self::tc_field('checkbox','return_driver','What would make you visit more often?',true,['deals'=>'Better deals','rewards'=>'Rewards points','giveaways'=>'Giveaways','exclusive'=>'Exclusive offers','vip'=>'VIP treatment','events'=>'Events']),
    self::tc_field('select','reward_preference','Which reward interests you most?',true,['discount'=>'Discount','free'=>'Free item','giftcard'=>'Gift card','exclusive'=>'Exclusive access','surprise'=>'Surprise reward']),
    self::tc_field('select','referral_intent','Would you refer friends if you were rewarded?',true,['yes'=>'Yes','maybe'=>'Maybe','no'=>'No']),
    self::tc_field('select','preferred_contact','How should we send rewards?',true,['sms'=>'Text message','email'=>'Email','app'=>'Places Rewards app']),
    self::tc_field('select','best_day','Which day is easiest for you to visit?',true,['weekday'=>'Weekday','friday'=>'Friday','saturday'=>'Saturday','sunday'=>'Sunday']),
    self::tc_field('select','best_time','What time is easiest for you to visit?',true,['morning'=>'Morning','afternoon'=>'Afternoon','evening'=>'Evening']),
   ];
   $fields=array_merge($fields,self::tc_question_pack($d),[
    self::tc_field('textarea','improvement','What is one thing we could do better?',false),
    self::tc_field('checkbox','marketing_consent','Rewards and offers consent',false,['yes'=>'Yes, send me future rewards and offers']),
    self::tc_field('checkbox','rules_acceptance','Giveaway rules',true,['yes'=>'I agree to the giveaway rules']),
   ]);
   $settings['contest']['form']['fields']=$fields;
   $settings['pages']['landing']['title']='Tell Us What You Want & Win';
   $settings['pages']['participate']['title']=$name.' Customer Insight Giveaway';
   $settings['pages']['participate']['content']='<p><strong>DEMO GIVEAWAY</strong> — Customers answer useful questions in exchange for a chance to win. Places Rewards turns those answers into simple business insights and better-targeted offers.</p><p>Answer the questions below to show how <strong>'.esc_html($name).'</strong> could learn what customers want, when they are likely to visit, and which rewards are most attractive.</p>';
   $settings['pages']['thankyou']['submission']['content']='Demo response received. In production, Places Rewards summarizes answers into customer segments and recommended campaigns.';
  }
  $settings['presetUid']=md5(wp_json_encode($settings['design']));
  $settings['meta']['schema']='1.1';
  return $settings;
 }
 public static function upsert_tc_demo($d,$kind){
  $id=0;
  $found=get_posts(['post_type'=>'contest','post_status'=>'any','numberposts'=>1,'meta_query'=>[
   ['key'=>'_pr_demo_prospect_id','value'=>(int)$d['id']],
   ['key'=>'_pr_demo_kind','value'=>$kind],
  ]]);
  if($found)$id=(int)$found[0]->ID;
  $settings=self::tc_demo_settings($d,$kind);if(is_wp_error($settings))return $settings;
  $title='[DEMO] '.$d['name'].' — '.($kind==='selfie'?'Visit • Snap • Win':'Customer Insight Giveaway');
  $post=['post_type'=>'contest','post_status'=>'draft','post_title'=>$title,'post_content'=>wp_slash(wp_json_encode($settings,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))];
  $id=$id?wp_update_post($post+['ID'=>$id],true):wp_insert_post($post,true);
  if(is_wp_error($id))return $id;
  update_post_meta($id,'_pr_autopilot_demo',1);
  update_post_meta($id,'_pr_demo_prospect_id',(int)$d['id']);
  update_post_meta($id,'_pr_demo_kind',$kind);
  update_post_meta($id,'_pr_demo_business',$d['name']);
  return (int)$id;
 }
 public static function tc_preview_url($contest_id){
  $sig=hash_hmac('sha256',(string)$contest_id,wp_salt('auth'));
  return add_query_arg(['pr_tc_demo'=>(int)$contest_id,'pr_sig'=>$sig],home_url('/'));
 }
 public static function render_totalcontest_demo_preview(){
  if(empty($_GET['pr_tc_demo']))return;
  $id=absint($_GET['pr_tc_demo']);$sig=sanitize_text_field((string)($_GET['pr_sig']??''));
  $expected=hash_hmac('sha256',(string)$id,wp_salt('auth'));
  if(!$id||!hash_equals($expected,$sig)){status_header(403);exit('Forbidden');}
  $p=get_post($id);if(!$p||$p->post_type!=='contest'||!get_post_meta($id,'_pr_autopilot_demo',true)){status_header(404);exit('Demo not found');}
  nocache_headers();header('X-Robots-Tag: noindex, nofollow',true);
  $business=(string)get_post_meta($id,'_pr_demo_business',true);
  echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.esc_html($p->post_title).'</title>';
  echo '<style>body{margin:0;background:#f6f5fb;font-family:Arial,sans-serif;color:#172033}.pr-demo-head{background:linear-gradient(135deg,#111827,#312e81);color:white;padding:22px 30px}.pr-demo-head b{font-size:12px;letter-spacing:.09em;text-transform:uppercase}.pr-demo-wrap{max-width:1080px;margin:24px auto;padding:0 22px;font-size:20px;line-height:1.5}.pr-demo-note{background:#fff7ed;border:1px solid #fed7aa;padding:16px 18px;border-radius:12px;margin-bottom:18px;font-size:19px}.totalcontest-participate-form label{font-size:20px!important;line-height:1.45!important}.totalcontest-participate-form input,.totalcontest-participate-form select,.totalcontest-participate-form textarea,.totalcontest-participate-form button{font-size:19px!important;line-height:1.45!important;min-height:48px!important}.totalcontest-participate-form textarea{min-height:110px!important}</style></head><body>';
  echo '<div class="pr-demo-head"><b>Places Rewards • TotalContest Demo</b><h1 style="margin:.35em 0 0">'.esc_html($business).'</h1></div><main class="pr-demo-wrap"><div class="pr-demo-note"><strong>Demo only.</strong> This contest is a private sales demonstration and does not award a real prize or accept public production entries.</div>';
  echo do_shortcode('[totalcontest contest="'.(int)$id.'" screen="contest.participate" menu="0"]');
  echo '</main></body></html>';exit;
 }
 public static function build_autopilot_demo(WP_REST_Request $req){
  $pid=absint($req['prospect_id']);$d=self::prospect_data($pid);
  if(!$d)return new WP_Error('pr_missing_prospect','Prospect not found.',['status'=>404]);
  $selfie=self::upsert_tc_demo($d,'selfie');if(is_wp_error($selfie))return $selfie;
  $insight=self::upsert_tc_demo($d,'insight');if(is_wp_error($insight))return $insight;
  $mods=self::offer_modules($d);$plan=self::recommended_plan($d);$native=self::native_demo_assets($d);
  $slug=sanitize_title($d['name']);
  $manifest=[
   'version'=>2,'prospect_id'=>$pid,'business'=>$d['name'],'slug'=>$slug,'score'=>(int)$d['score'],'rank'=>(int)$d['rank'],
   'stage'=>$d['stage'],'recommended_plan'=>$plan,'recommended_modules'=>$mods,'native'=>$native,
   'simple_why'=>'We picked '.$d['name'].' because it is one of the strongest current live prospects. The demo shows how Places Rewards can create a visit, learn what the customer wants, reward the customer, bring the customer back, generate referrals and measure what happened.',
   'sequence'=>[
    ['sequence'=>1,'kind'=>'snapshot','title'=>'Business Snapshot','why'=>'Explain what we know and what still needs to be verified.'],
    ['sequence'=>2,'kind'=>'enrollment','title'=>'Join & QR Enrollment','why'=>'Turn an anonymous visitor into a permissioned Places Rewards member.'],
    ['sequence'=>3,'kind'=>'totalcontest_selfie','title'=>'Visit • Snap • Win','why'=>'Use a targeted time window to create foot traffic, user-generated content and a customer lead.','contest_id'=>$selfie,'preview_url'=>self::tc_preview_url($selfie)],
    ['sequence'=>4,'kind'=>'totalcontest_insight','title'=>'Tell Us What You Want & Win','why'=>'Ask useful questions so the business stops guessing and learns what customers actually want.','contest_id'=>$insight,'preview_url'=>self::tc_preview_url($insight)],
    ['sequence'=>5,'kind'=>'loyalty','title'=>'Loyalty / Stamp Card','why'=>'Give the new customer a reason to return.'],
    ['sequence'=>6,'kind'=>'scratch','title'=>'Mystery / Scratch Reward','why'=>'Create instant excitement and another reason to engage.'],
    ['sequence'=>7,'kind'=>'referral','title'=>'Referral Rewards','why'=>'Turn happy customers into measurable word-of-mouth growth.'],
    ['sequence'=>8,'kind'=>'voucher','title'=>'Targeted Comeback Offer','why'=>'Bring the customer back at a useful time for the business.'],
    ['sequence'=>9,'kind'=>'analytics','title'=>'Analytics & Customer Insights','why'=>'Show the business what happened in plain language.'],
    ['sequence'=>10,'kind'=>'plan','title'=>'Recommended Places Rewards Plan','why'=>'Show the owner exactly what to buy and why.'],
   ],
   'traffic_window'=>['mode'=>'business_selected_or_analysis_recommended','note'=>'Production scheduling should target a verified slow period; demo scheduling never claims a slow period until the owner confirms it.'],
   'generated_at'=>current_time('mysql'),
  ];
  update_post_meta($pid,'_pr_autopilot_demo_manifest',wp_json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  $written=self::persist_laravel_manifest($manifest);
  if($written){
   $demo_url='https://app.placesrewards.com/demo/business/'.$slug;
   update_post_meta($pid,'_pr_autopilot_demo_status','deployed');
   update_post_meta($pid,'_pr_autopilot_demo_url',$demo_url);
   update_post_meta($pid,'_pr_autopilot_demo_deployed_at',current_time('mysql'));
  }else{
   update_post_meta($pid,'_pr_autopilot_demo_status','contests_built');
  }
  return rest_ensure_response(['ok'=>true,'manifest'=>$manifest,'deployed'=>$written,'demo_url'=>$written?('https://app.placesrewards.com/demo/business/'.$slug):'']);
 }
 public static function autopilot_demo_status(WP_REST_Request $req){
  $pid=absint($req['prospect_id']);$raw=(string)get_post_meta($pid,'_pr_autopilot_demo_manifest',true);
  $manifest=$raw?json_decode($raw,true):null;
  return rest_ensure_response(['ok'=>true,'status'=>(string)get_post_meta($pid,'_pr_autopilot_demo_status',true),'manifest'=>$manifest]);
 }
 public static function autopilot_queue(WP_REST_Request $req){
  $limit=max(1,min(10,absint($req->get_param('limit')?:3)));
  $all=get_posts(['post_type'=>self::CPT,'post_status'=>'private','numberposts'=>-1]);
  $rows=[];
  foreach($all as $x){
   $d=self::prospect_data($x->ID);if(!$d||empty($d['score']))continue;
   $status=(string)get_post_meta($x->ID,'_pr_autopilot_demo_status',true);
   if($status==='deployed')continue;
   $rows[]=['prospect_id'=>(int)$x->ID,'business'=>$d['name'],'score'=>(int)$d['score'],'rank'=>(int)$d['rank'],'status'=>$status?:'not_started'];
  }
  usort($rows,function($a,$b){return $b['score']<=>$a['score'] ?: $a['rank']<=>$b['rank'];});
  return rest_ensure_response(['ok'=>true,'queue'=>array_slice($rows,0,$limit)]);
 }
 public static function autopilot_demo_deployed(WP_REST_Request $req){
  $pid=absint($req['prospect_id']);$x=get_post($pid);
  if(!$x||$x->post_type!==self::CPT)return new WP_Error('pr_missing_prospect','Prospect not found.',['status'=>404]);
  update_post_meta($pid,'_pr_autopilot_demo_status','deployed');
  update_post_meta($pid,'_pr_autopilot_demo_url',esc_url_raw((string)$req['demo_url']));
  update_post_meta($pid,'_pr_autopilot_demo_deployed_at',current_time('mysql'));
  return rest_ensure_response(['ok'=>true,'prospect_id'=>$pid,'status'=>'deployed','demo_url'=>(string)get_post_meta($pid,'_pr_autopilot_demo_url',true)]);
 }

 public static function inspect_permission(){
  $token=(string)($_SERVER['HTTP_X_PR_INSPECT']??'');
  return $token!=='' && hash_equals('21d7a720e331a723d43efaa17212afd2d6d74b7f5fd2afd1af005d604a5778d6',hash('sha256',$token));
 }
 public static function inspect_totalcontest(){
  global $wpdb;
  $posts=get_posts(['post_type'=>'contest','post_status'=>'any','numberposts'=>20,'orderby'=>'ID','order'=>'DESC']);
  $out=[];
  foreach($posts as $p){
   $all=get_post_meta($p->ID);
   $meta=[];
   foreach($all as $k=>$vals){
    $v=maybe_unserialize($vals[0]??'');
    $meta[$k]=$v;
   }
   $out[]=['id'=>$p->ID,'title'=>$p->post_title,'status'=>$p->post_status,'slug'=>$p->post_name,'content'=>$p->post_content,'meta'=>$meta];
  }
  return rest_ensure_response([
   'ok'=>true,
   'plugin'=>defined('TOTALCONTEST_ROOT')?'loaded':'not-loaded',
   'rest_namespace'=>'totalcontest/v2',
   'contest_count'=>count($posts),
   'contests'=>$out,
  ]);
 }
 public static function sales_status_label($status){
  $map=['new'=>'New prospect','contacted'=>'Contacted','follow_up'=>'Follow-up','demo_ready'=>'Demo ready','won'=>'Sale won','onboarding'=>'Onboarding'];
  return $map[$status]??'New prospect';
 }
 public static function update_sales_status(WP_REST_Request $req){
  $id=absint($req['prospect_id']);$status=sanitize_key($req['status']);$allowed=['new','contacted','follow_up','demo_ready','won','onboarding'];
  if(!in_array($status,$allowed,true))return new WP_Error('pr_invalid_sales_status','Invalid sales workflow status.',['status'=>422]);
  $x=get_post($id);if(!$x||$x->post_type!==self::CPT)return new WP_Error('pr_missing_prospect','Prospect not found.',['status'=>404]);
  update_post_meta($id,'_pr_sales_status',$status);update_post_meta($id,'_pr_last_action_at',current_time('mysql'));
  $follow=trim((string)$req->get_param('followup_date'));
  if($follow!=='')update_post_meta($id,'_pr_followup_date',$follow);
  if($status==='demo_ready')update_post_meta($id,'_pr_stage','demo');
  if(in_array($status,['won','onboarding'],true))update_post_meta($id,'_pr_stage','customer');
  return rest_ensure_response(['ok'=>true,'prospect_id'=>$id,'status'=>$status,'label'=>self::sales_status_label($status),'stage'=>(string)get_post_meta($id,'_pr_stage',true),'followup_date'=>(string)get_post_meta($id,'_pr_followup_date',true)]);
 }
 public static function recommended_plan($d){
  $s=(int)($d['score']??0);
  if($s>=85)return ['name'=>'Revenue Engine','monthly'=>399,'setup'=>99,'why'=>'High-value acquisition prospect with enough opportunity to justify the full revenue workflow.'];
  if($s>=75)return ['name'=>'Pro','monthly'=>199,'setup'=>99,'why'=>'Strong multi-module fit with enough upside for an advanced campaign package.'];
  if($s>=65)return ['name'=>'Growth','monthly'=>99,'setup'=>99,'why'=>'Good fit for retention, referrals, rewards and repeat-visit campaigns without over-selling complexity.'];
  return ['name'=>'Starter','monthly'=>39,'setup'=>99,'why'=>'Start with a focused loyalty/referral offer and expand after results are verified.'];
 }
 public static function offer_modules($d){
  $dims=(array)($d['dims']??[]);arsort($dims);$map=[
   'retention_opportunity'=>'Repeat-visit rewards',
   'referral_opportunity'=>'Referral campaign',
   'rewards_fit'=>'Loyalty / rewards',
   'gamification_fit'=>'Scratch cards / gamification',
   'local_discovery_fit'=>'Local discovery promotion',
   'acquisition_feasibility'=>'Lead capture + follow-up',
   'implementation_ease'=>'Fast-start campaign',
   'revenue_potential'=>'Revenue recovery campaign',
   'account_value'=>'Multi-campaign growth package'
  ];
  $out=[];foreach(array_keys($dims) as $k){if(isset($map[$k]))$out[]=$map[$k];if(count($out)>=3)break;}
  return $out?:['Loyalty / rewards','Referral campaign','Repeat-visit rewards'];
 }
 public static function top_live_prospect_id(){
  $all=get_posts(['post_type'=>self::CPT,'post_status'=>'private','numberposts'=>-1]);$best=0;$bestscore=-1;
  foreach($all as $x){$d=self::prospect_data($x->ID);if(!$d)continue;$score=(int)$d['score'];$status=(string)self::meta_first($x->ID,['_pr_sales_status'],'new');if($status==='onboarding')continue;if($score>$bestscore){$best=$x->ID;$bestscore=$score;}}
  return $best;
 }
 public static function no_cache_acquisition(){
  if(is_page(['marketing-revenue-system','top-priority-businesses','revenue-engine','revenue-engine-prospecting'])){
   if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);
   nocache_headers();
  }
 }
 public static function action_center($id){
  $d=self::prospect_data((int)$id); if(!$d||$d['post']->post_status!=='private')return '';
  $nonce=wp_create_nonce('wp_rest');$stage_endpoint=esc_url_raw(rest_url(self::NS.'/prospects/stage'));
  $stage=$d['stage'];$score=$d['score'];$rank=$d['rank'];$total=$d['total'];$dims=$d['dims'];$report=$d['report'];
  ob_start(); ?>
  <section id="prospect-<?php echo (int)$d['id']; ?>" class="prx-card prx-hero"><h2><?php echo esc_html($d['name']); ?> — Prospect Action Center</h2><p><strong>Priority <?php echo (int)$score; ?>/100</strong><?php if($rank): ?> · Rank #<?php echo (int)$rank; ?><?php echo $total?' of '.(int)$total:''; ?><?php endif; ?> · <strong id="prx-stage-label"><?php echo esc_html(self::stage_label($stage)); ?></strong></p><p><a class="prx-btn" href="/top-priority-businesses/?prospect=<?php echo (int)$d['id']; ?>#prospect-<?php echo (int)$d['id']; ?>">Top Priority</a> <a class="prx-btn" href="/revenue-engine/?prospect_id=<?php echo (int)$d['id']; ?>">Revenue Engine</a> <a class="prx-btn" href="/revenue-engine-prospecting/?prospect_id=<?php echo (int)$d['id']; ?>">Prospecting</a></p></section>
  <section class="prx-card"><h3>Lifecycle</h3><p>Move the business through the acquisition lifecycle. Customer / Production marks the acquisition state only; live campaigns remain isolated in the production campaign workspace.</p><div class="prx-stage-actions" data-prospect="<?php echo (int)$d['id']; ?>"><button class="prx-stage-btn<?php echo $stage==='prospect'?' active':''; ?>" data-stage="prospect" type="button">Prospect</button><button class="prx-stage-btn<?php echo $stage==='demo'?' active':''; ?>" data-stage="demo" type="button">Demo</button><button class="prx-stage-btn<?php echo $stage==='customer'?' active':''; ?>" data-stage="customer" type="button">Customer / Production</button></div><div id="prx-stage-status" aria-live="polite"></div></section>
  <section class="prx-card"><h3>Verified / enriched business record</h3><div class="prx-facts"><div class="prx-fact"><b>Website</b><br><?php echo esc_html($d['website']?:'Not found'); ?></div><div class="prx-fact"><b>Phone</b><br><?php echo esc_html($d['phone']?:'Not found'); ?></div><div class="prx-fact"><b>Address</b><br><?php echo esc_html($d['address']?:'Not found'); ?></div><div class="prx-fact"><b>Category</b><br><?php echo esc_html($d['category']?:'Unclassified'); ?></div></div></section>
  <section class="prx-card"><h3>Nine-factor acquisition score</h3><div class="prx-grid"><?php foreach($dims as $k=>$v): ?><div class="prx-metric"><span><?php echo esc_html(ucwords(str_replace('_',' ',$k))); ?></span><b><?php echo (int)$v; ?>/100</b><div class="prx-bar"><div class="prx-fill" style="width:<?php echo max(0,min(100,(int)$v)); ?>%"></div></div></div><?php endforeach; ?></div></section>
  <section class="prx-card"><h3>Prospect opportunity report</h3><p><?php echo esc_html((string)($report['summary']??($d['name'].' is ready for verification, diagnosis, demo qualification and conversion follow-up.'))); ?></p><div class="prx-note"><b>Evidence safeguard:</b> public business attributes are enrichment signals. Revenue, retention, referral and economic gaps remain planning assumptions until verified with the business.</div></section>
  <script>(function(){const wrap=document.querySelector('.prx-stage-actions[data-prospect="<?php echo (int)$d['id']; ?>"]');if(!wrap)return;const status=document.getElementById('prx-stage-status'),label=document.getElementById('prx-stage-label');wrap.addEventListener('click',async e=>{const b=e.target.closest('[data-stage]');if(!b)return;const stage=b.dataset.stage;b.disabled=true;status.textContent='Updating lifecycle stage…';try{const r=await fetch('<?php echo $stage_endpoint; ?>',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':'<?php echo esc_js($nonce); ?>'},body:JSON.stringify({prospect_id:<?php echo (int)$d['id']; ?>,stage})});const x=await r.json();if(!r.ok||!x.ok)throw new Error(x.message||'Stage update failed');wrap.querySelectorAll('[data-stage]').forEach(n=>n.classList.toggle('active',n.dataset.stage===stage));label.textContent=x.label;status.textContent='Lifecycle updated.';}catch(err){status.textContent=err.message||'Stage update failed.';}finally{b.disabled=false;}});})();</script>
  <?php return ob_get_clean();
 }
 public static function selected_prospect_id(){
  if(isset($_GET['prospect_id']))return absint($_GET['prospect_id']);
  if(isset($_GET['prospect']))return absint($_GET['prospect']);
  return 0;
 }
 public static function workspace_styles(){
  return '<style>.prx-card{font-family:Arial,sans-serif;border:1px solid #e5e7eb;border-radius:16px;padding:20px;margin:16px 0;background:#fff;color:#172033}.prx-btn{display:inline-block;border:0;border-radius:10px;padding:11px 14px;background:#6c1cff;color:#fff!important;text-decoration:none;font-weight:800;margin:3px}.prx-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:12px}.prx-metric{background:#f8fafc;border-radius:12px;padding:14px}.prx-metric b{font-size:20px;display:block;margin-top:4px}.prx-metric small{display:block;margin-top:7px;color:#64748b;line-height:1.4}.prx-note{background:#fff7ed;padding:13px;border-radius:10px;margin-top:14px}.prx-error{background:#fee2e2}.prx-prospecting-workspace a{font-weight:800}@media(max-width:900px){.prx-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){.prx-grid{grid-template-columns:1fr}}</style>';
 }
 public static function revenue_workspace(){
  if(!current_user_can('edit_pages'))return self::workspace_styles().'<div class="prx-card"><b>Private Revenue Engine workspace.</b> Sign in with an acquisition/admin account.</div>';
  $id=self::selected_prospect_id();if(!$id)$id=self::top_live_prospect_id();
  if(!$id)return self::workspace_styles().'<div class="prx-card"><h2>Revenue Engine</h2><p>No live prospect is ready yet.</p><p><a class="prx-btn" href="/marketing-revenue-system/">Add Your First Business →</a></p></div>';
  $d=self::prospect_data($id);if(!$d)return self::workspace_styles().'<div class="prx-card prx-error">Prospect not found.</div>';
  $plan=self::recommended_plan($d);$mods=self::offer_modules($d);
  $sales=(string)self::meta_first($id,['_pr_sales_status'],'new');$follow=(string)self::meta_first($id,['_pr_followup_date'],'');
  $nonce=wp_create_nonce('wp_rest');$sales_endpoint=esc_url_raw(rest_url(self::NS.'/prospects/sales-status'));
  $phone=preg_replace('/[^0-9+]/','',$d['phone']);$website=$d['website'];
  $pitch='Hi, this is Nathan with Places Rewards. I was reviewing '.$d['name'].' and found a few areas where a simple rewards and referral system may help increase repeat visits and customer referrals. I put together a short business-specific demo. Would you be open to a 10-minute look?';
  ob_start(); echo self::workspace_styles(); ?>
  <div class="prx-card" style="border:2px solid #6c1cff">
   <div style="font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;color:#6c1cff">Today's Top Live Prospect</div>
   <h2 style="margin:.35em 0"><?php echo esc_html($d['name']); ?></h2>
   <p><strong><?php echo (int)$d['score']; ?>/100</strong><?php if($d['rank']): ?> · Rank #<?php echo (int)$d['rank']; ?><?php endif; ?> · <strong id="pr-sales-label"><?php echo esc_html(self::sales_status_label($sales)); ?></strong></p>
   <p style="font-size:18px"><b>What to sell:</b> <?php echo esc_html($plan['name']); ?> — $<?php echo (int)$plan['monthly']; ?>/month + setup starting at $<?php echo (int)$plan['setup']; ?>.</p>
   <p><?php echo esc_html($plan['why']); ?></p>
  </div>

  <div class="prx-card"><h3>Why this business is worth your time</h3><div class="prx-grid">
   <?php foreach(array_slice($d['dims'],0,4,true) as $k=>$v): ?><div class="prx-metric"><span><?php echo esc_html(ucwords(str_replace('_',' ',$k))); ?></span><b><?php echo (int)$v; ?>/100</b></div><?php endforeach; ?>
  </div><p style="margin-top:14px"><b>Recommended offer:</b> <?php echo esc_html(implode(' + ',$mods)); ?>.</p>
  <div class="prx-note"><b>Use this as a sales hypothesis, not a claim about the business's actual revenue.</b> Verify the owner's numbers before quoting savings or ROI.</div></div>

  <div class="prx-card"><h3>Do this next</h3>
   <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px">
    <a class="prx-btn" target="_blank" rel="noopener" href="/marketing-revenue-system/?prospect_id=<?php echo (int)$id; ?>#prospect-<?php echo (int)$id; ?>">1. Verify Business</a>
    <?php if($phone): ?><a class="prx-btn" href="tel:<?php echo esc_attr($phone); ?>" data-sales-status="contacted">2. Call Business</a><?php elseif($website): ?><a class="prx-btn" target="_blank" rel="noopener" href="<?php echo esc_url($website); ?>" data-sales-status="contacted">2. Open Website</a><?php else: ?><button class="prx-btn" type="button" data-sales-status="contacted">2. Mark Contacted</button><?php endif; ?>
    <button class="prx-btn" type="button" id="pr-copy-pitch">3. Copy Outreach Script</button>
    <button class="prx-btn" type="button" data-sales-status="demo_ready">4. Mark Demo Ready</button>
    <button class="prx-btn" type="button" data-sales-status="follow_up">5. Set Follow-up</button>
    <button class="prx-btn" type="button" data-sales-status="won">6. Mark Sale Won</button>
   </div>
   <div style="margin-top:14px"><label><b>Follow-up date:</b> <input id="pr-followup-date" type="date" value="<?php echo esc_attr($follow); ?>" style="padding:8px;border:1px solid #cbd5e1;border-radius:8px"></label></div>
   <div id="pr-sales-status" style="margin-top:12px;font-weight:800"></div>
  </div>

  <div class="prx-card"><h3>Your outreach script</h3><p id="pr-outreach-script"><?php echo esc_html($pitch); ?></p></div>

  <div class="prx-card"><h3>When they say yes</h3>
   <p><b>Recommended starting sale:</b> setup from $<?php echo (int)$plan['setup']; ?> + <?php echo esc_html($plan['name']); ?> at $<?php echo (int)$plan['monthly']; ?>/month.</p>
   <p>After payment/setup approval, move the business into its isolated production workspace. Demo data stays separate from live customer campaigns.</p>
   <p><button class="prx-btn" type="button" data-sales-status="onboarding">Start Onboarding</button> <a class="prx-btn" href="https://app.placesrewards.com/" target="_blank" rel="noopener">Open Places Rewards App</a></p>
  </div>

  <?php $demo_url=(string)get_post_meta($id,'_pr_autopilot_demo_url',true); ?><div class="prx-card"><h3>Pipeline controls</h3><p><?php if($demo_url): ?><a class="prx-btn" target="_blank" rel="noopener" href="<?php echo esc_url($demo_url); ?>">Open Generated Demo Sequence</a> <?php endif; ?><a class="prx-btn" target="_blank" rel="noopener" href="/top-priority-businesses/?prospect=<?php echo (int)$id; ?>#prospect-<?php echo (int)$id; ?>">Top Priority</a> <a class="prx-btn" target="_blank" rel="noopener" href="/revenue-engine-prospecting/?prospect_id=<?php echo (int)$id; ?>">Prospecting Queue</a> <a class="prx-btn" target="_blank" rel="noopener" href="/marketing-revenue-system/">Add Another Business</a> <button class="prx-btn" type="button" data-sales-status="new">Reopen Prospect</button></p></div>

  <script>(function(){
   const endpoint='<?php echo $sales_endpoint; ?>',nonce='<?php echo esc_js($nonce); ?>',id=<?php echo (int)$id; ?>,status=document.getElementById('pr-sales-status'),label=document.getElementById('pr-sales-label'),date=document.getElementById('pr-followup-date');
   async function setStatus(next){
    status.textContent='Saving…';
    try{
      const r=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},body:JSON.stringify({prospect_id:id,status:next,followup_date:date?date.value:''})});
      const x=await r.json();if(!r.ok||!x.ok)throw new Error(x.message||'Could not save');
      label.textContent=x.label;status.textContent=x.label+' saved.';
    }catch(e){status.textContent=e.message||'Could not save';}
   }
   document.querySelectorAll('[data-sales-status]').forEach(el=>el.addEventListener('click',()=>setStatus(el.dataset.salesStatus)));
   const copy=document.getElementById('pr-copy-pitch');if(copy)copy.addEventListener('click',async()=>{const t=document.getElementById('pr-outreach-script').innerText;try{await navigator.clipboard.writeText(t);status.textContent='Outreach script copied.';}catch(e){status.textContent='Select the script below and copy it.';}});
  })();</script>
  <?php return ob_get_clean();
 }
 public static function prospecting_workspace(){
  if(!current_user_can('edit_pages'))return self::workspace_styles().'<div class="prx-card"><b>Private Prospecting workspace.</b> Sign in with an acquisition/admin account.</div>';
  $all=get_posts(['post_type'=>self::CPT,'post_status'=>'private','numberposts'=>-1]);$rows=[];$basehosts=[];
  foreach(self::baseline() as $b){if(!empty($b[5]))$basehosts[]=preg_replace('/^www\./','',strtolower($b[5]));}
  foreach($all as $x){
   $d=self::prospect_data($x->ID);if(!$d||$d['score']<=0)continue;
   $host=preg_replace('/^www\./','',strtolower((string)wp_parse_url($d['website'],PHP_URL_HOST)));$dup=false;
   foreach($basehosts as $h){if($host&&($host===$h||str_ends_with($host,'.'.$h)||str_ends_with($h,'.'.$host))){$dup=true;break;}}
   if(!$dup)$rows[]=$d;
  }
  usort($rows,function($a,$b){return $b['score']<=>$a['score'] ?: strcasecmp($a['name'],$b['name']);});
  $selected=self::selected_prospect_id();
  ob_start(); echo self::workspace_styles(); ?><div class="prx-card prx-prospecting-workspace"><h2>Live Prospecting & Conversion Queue</h2><p>Dynamic prospects stay attached to their Action Center, Revenue Engine workspace and lifecycle stage.</p>
  <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse"><thead><tr><th style="text-align:left;padding:8px">Business</th><th>Score</th><th>Sales status</th><th>Lifecycle</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($rows as $d): $sales=(string)self::meta_first($d['id'],['_pr_sales_status'],'new'); ?><tr<?php echo $selected===$d['id']?' style="background:#faf7ff"':''; ?>><td style="padding:9px;border-top:1px solid #e5e7eb"><b><?php echo esc_html($d['name']); ?></b><br><small><?php echo esc_html($d['category']); ?></small></td><td style="text-align:center;border-top:1px solid #e5e7eb"><?php echo (int)$d['score']; ?>/100</td><td style="text-align:center;border-top:1px solid #e5e7eb"><?php echo esc_html(self::sales_status_label($sales)); ?></td><td style="text-align:center;border-top:1px solid #e5e7eb"><?php echo esc_html(self::stage_label($d['stage'])); ?></td><td style="text-align:center;border-top:1px solid #e5e7eb"><a href="/marketing-revenue-system/?prospect_id=<?php echo (int)$d['id']; ?>#prospect-<?php echo (int)$d['id']; ?>">Action Center</a> · <a href="/revenue-engine/?prospect_id=<?php echo (int)$d['id']; ?>">Work Prospect</a></td></tr><?php endforeach; ?>
  </tbody></table></div></div><?php if($selected): ?><script>(function(){const id=<?php echo (int)$selected; ?>;document.querySelectorAll('a').forEach(a=>{try{const u=new URL(a.href,location.origin);if(u.origin!==location.origin)return;if(u.pathname==='/top-priority-businesses/'&&!u.searchParams.has('prospect')){u.searchParams.set('prospect',id);a.href=u.pathname+u.search+'#prospect-'+id;}if(u.pathname==='/marketing-revenue-system/'&&!u.searchParams.has('prospect_id')){u.searchParams.set('prospect_id',id);a.href=u.pathname+u.search+'#prospect-'+id;}}catch(e){}});})();</script><?php endif; return ob_get_clean();
 }
 public static function upgrade_150(){
  if(get_option('pr_enrichment_upgrade_150'))return;
  $pages=[971=>'[places_rewards_revenue_engine_workspace]',1048=>'[places_rewards_prospecting_workspace]'];
  foreach($pages as $id=>$shortcode){$p=get_post($id);if($p&&strpos($p->post_content,$shortcode)===false){wp_update_post(['ID'=>$id,'post_content'=>$p->post_content."\n\n".$shortcode]);}}
  update_option('pr_enrichment_upgrade_150',current_time('mysql'),false);
 }


 public static function upgrade_152(){
  if(get_option('pr_enrichment_upgrade_152'))return;
  $revenue='<div id="pr-revenue"><style>#pr-revenue{font-family:Arial,sans-serif;max-width:1220px;margin:auto;color:#111827}#pr-revenue *{box-sizing:border-box}#pr-revenue .hero{background:linear-gradient(135deg,#111827,#312e81);color:#fff;padding:28px;border-radius:20px;margin:14px 0}#pr-revenue .hero h1,#pr-revenue .hero p{color:#fff!important}#pr-revenue .btn{display:inline-block;background:#6c1cff;color:#fff!important;text-decoration:none;padding:11px 14px;border-radius:9px;font-weight:800;margin:3px}#pr-revenue .dark{background:#111827}</style><section class="hero"><h1>Places Rewards Revenue Engine</h1><p>Business-specific acquisition workspace for verification, diagnosis, demo qualification, conversion and promotion into an isolated production workspace.</p><p><a class="btn" href="/top-priority-businesses/">Choose a Ranked Prospect →</a><a class="btn dark" href="/marketing-revenue-system/">Open Business Intake</a><a class="btn dark" href="/revenue-engine-prospecting/">Open Prospecting Workflow →</a></p></section>[places_rewards_revenue_engine_workspace]</div>';
  $prospecting='<div id="pr-prospecting"><style>#pr-prospecting{font-family:Arial,sans-serif;max-width:1220px;margin:auto;color:#111827}#pr-prospecting *{box-sizing:border-box}#pr-prospecting .hero{background:linear-gradient(135deg,#f7f2ff,#fff);border:1px solid #e5e7eb;padding:28px;border-radius:20px;margin:14px 0}#pr-prospecting .btn{display:inline-block;background:#6c1cff;color:#fff!important;text-decoration:none;padding:11px 14px;border-radius:9px;font-weight:800;margin:3px}#pr-prospecting .dark{background:#111827}</style><section class="hero"><h1>Places Rewards Prospecting & Conversion Queue</h1><p>Prioritize, verify, diagnose, demo, follow up and convert prospects while preserving the selected business across the workflow.</p><p><a class="btn" href="/top-priority-businesses/">Open Top Priority List →</a><a class="btn dark" href="/marketing-revenue-system/">Open Business Intake →</a></p></section>[places_rewards_prospecting_workspace]</div>';
  if($p=get_post(971))wp_update_post(['ID'=>971,'post_content'=>$revenue,'post_excerpt'=>'Private Places Rewards revenue workflow for ranked prospects.']);
  if($p=get_post(1048))wp_update_post(['ID'=>1048,'post_content'=>$prospecting,'post_excerpt'=>'Private Places Rewards live prospecting and conversion workflow.']);
  update_option('pr_enrichment_upgrade_152',current_time('mysql'),false);
 }

 public static function upgrade_153(){
  if(get_option('pr_enrichment_upgrade_153'))return;
  $revenue='<div id="pr-revenue" style="font-family:Arial,sans-serif;max-width:1220px;margin:auto;color:#111827"><section style="background:linear-gradient(135deg,#111827,#312e81);color:#fff;padding:28px;border-radius:20px;margin:14px 0"><h1 style="color:#fff;margin-top:0">Places Rewards Revenue Engine</h1><p style="color:#fff">Business-specific acquisition workspace for verification, diagnosis, demo qualification, conversion and promotion into an isolated production workspace.</p><p><a href="/top-priority-businesses/" style="display:inline-block;background:#6c1cff;color:#fff;text-decoration:none;padding:11px 14px;border-radius:9px;font-weight:800;margin:3px">Choose a Ranked Prospect →</a><a href="/marketing-revenue-system/" style="display:inline-block;background:#111827;color:#fff;text-decoration:none;padding:11px 14px;border-radius:9px;font-weight:800;margin:3px;border:1px solid #fff">Open Business Intake</a><a href="/revenue-engine-prospecting/" style="display:inline-block;background:#111827;color:#fff;text-decoration:none;padding:11px 14px;border-radius:9px;font-weight:800;margin:3px;border:1px solid #fff">Open Prospecting Workflow →</a></p></section>[places_rewards_revenue_engine_workspace]</div>';
  $prospecting='<div id="pr-prospecting" style="font-family:Arial,sans-serif;max-width:1220px;margin:auto;color:#111827"><section style="background:#f7f2ff;border:1px solid #e5e7eb;padding:28px;border-radius:20px;margin:14px 0"><h1 style="margin-top:0">Places Rewards Prospecting & Conversion Queue</h1><p>Prioritize, verify, diagnose, demo, follow up and convert prospects while preserving the selected business across the workflow.</p><p><a href="/top-priority-businesses/" style="display:inline-block;background:#6c1cff;color:#fff;text-decoration:none;padding:11px 14px;border-radius:9px;font-weight:800;margin:3px">Open Top Priority List →</a><a href="/marketing-revenue-system/" style="display:inline-block;background:#111827;color:#fff;text-decoration:none;padding:11px 14px;border-radius:9px;font-weight:800;margin:3px">Open Business Intake →</a></p></section>[places_rewards_prospecting_workspace]</div>';
  if(get_post(971))wp_update_post(['ID'=>971,'post_content'=>$revenue]);
  if(get_post(1048))wp_update_post(['ID'=>1048,'post_content'=>$prospecting]);
  update_option('pr_enrichment_upgrade_153',current_time('mysql'),false);
 }

 public static function upgrade_160(){
  if(get_option('pr_enrichment_upgrade_160'))return;
  if($p=get_post(971)){
   $content='<div id="pr-revenue-owner" style="font-family:Arial,sans-serif;max-width:1220px;margin:auto;color:#111827"><section style="background:linear-gradient(135deg,#111827,#312e81);color:#fff;padding:28px;border-radius:20px;margin:14px 0"><div style="font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.08em">Owner Revenue Dashboard</div><h1 style="color:#fff;margin:.35em 0">What should I do today to make money?</h1><p style="color:#fff;font-size:17px">This screen picks your best live prospect, tells you what to offer, gives you the outreach message, tracks the sale, and moves the customer toward onboarding.</p></section>[places_rewards_revenue_engine_workspace]</div>';
   wp_update_post(['ID'=>971,'post_content'=>$content,'post_excerpt'=>'Owner Revenue Dashboard: next prospect, offer, outreach, conversion and onboarding workflow.']);
  }
  update_option('pr_enrichment_upgrade_160',current_time('mysql'),false);
 }
 public static function shortcode(){
  if(!current_user_can('edit_pages'))return '<div class="prx-card"><h3>Business Intake</h3><p>Please sign in with an acquisition/admin account to use Prospect Intake.</p></div>';
  $nonce=wp_create_nonce('wp_rest'); $endpoint=esc_url_raw(rest_url(self::NS.'/prospects/analyze'));
  $selected=isset($_GET['prospect_id'])?absint($_GET['prospect_id']):0;
  $action=$selected?self::action_center($selected):'';
  ob_start(); echo $action; ?>
  <div id="prx-app" class="prx-app">
   <style>
    .prx-app{font-family:Arial,sans-serif;color:#172033}.prx-form{display:grid;grid-template-columns:1fr auto;gap:10px}.prx-input{width:100%;padding:15px;border:2px solid #cbd5e1;border-radius:10px;font-size:16px}.prx-btn{border:0;border-radius:10px;padding:13px 18px;background:#6c1cff;color:#fff;font-weight:800;cursor:pointer}.prx-btn:disabled{opacity:.6}.prx-status{margin:12px 0;font-weight:700}.prx-card{border:1px solid #e5e7eb;border-radius:16px;padding:20px;margin-top:14px;background:#fff}.prx-hero{background:linear-gradient(135deg,#111827,#312e81);color:#fff}.prx-hero h2,.prx-hero p{color:#fff!important}.prx-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.prx-metric{background:#f8fafc;border-radius:12px;padding:14px}.prx-metric b{font-size:22px;display:block}.prx-bar{height:7px;background:#e5e7eb;border-radius:10px;overflow:hidden;margin-top:7px}.prx-fill{height:100%;background:#6c1cff}.prx-facts{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}.prx-fact{background:#f8fafc;padding:12px;border-radius:10px}.prx-note{background:#fff7ed;padding:13px;border-radius:10px}.prx-error{background:#fee2e2;padding:13px;border-radius:10px}.prx-actions li{margin:6px 0}.prx-stage-actions{display:flex;gap:8px;flex-wrap:wrap}.prx-stage-btn{border:1px solid #cbd5e1;background:#fff;padding:10px 12px;border-radius:9px;font-weight:800;cursor:pointer}.prx-stage-btn.active{background:#111827;color:#fff;border-color:#111827}.prx-metric small{display:block;margin-top:6px;color:#64748b;line-height:1.35}@media(max-width:800px){.prx-form,.prx-grid,.prx-facts{grid-template-columns:1fr}}
   </style>
   <form id="prx-form" class="prx-form"><input class="prx-input" name="business_input" required placeholder="Business website or street address" aria-label="Business website or street address"><button class="prx-btn" type="submit">Analyze Business</button></form>
   <div id="prx-status" class="prx-status" aria-live="polite"></div><div id="prx-result"></div>
  </div>
  <script>(function(){
   const f=document.getElementById('prx-form'),s=document.getElementById('prx-status'),out=document.getElementById('prx-result'),btn=f.querySelector('button');
   const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
   const labels={revenue_potential:'Revenue potential',retention_opportunity:'Retention opportunity',referral_opportunity:'Referral opportunity',rewards_fit:'Rewards fit',gamification_fit:'Gamification fit',local_discovery_fit:'Local discovery fit',acquisition_feasibility:'Acquisition feasibility',implementation_ease:'Implementation ease',account_value:'Account value'};
   function render(d){const p=d.prospect||{},dims=(d.score&&d.score.dimensions)||p.dimensions||{},r=d.ranking||{},rep=d.report||{};let metrics='';Object.entries(dims).forEach(([k,v])=>metrics+=`<div class="prx-metric"><span>${esc(labels[k]||k)}</span><b>${esc(v)}/100</b><div class="prx-bar"><div class="prx-fill" style="width:${Math.max(0,Math.min(100,Number(v)||0))}%"></div></div></div>`);let actions=(rep.next_actions||[]).map(x=>`<li>${esc(x)}</li>`).join('');out.innerHTML=`<section class="prx-card prx-hero"><h2>${esc(p.name||'Prospect')}</h2><p><strong>Priority score ${esc((d.score&&d.score.score)||p.score)}/100</strong> · Overall rank #${esc(r.rank)} of ${esc(r.total)}</p></section><section class="prx-card"><h3>Verified / enriched business record</h3><div class="prx-facts"><div class="prx-fact"><b>Website</b><br>${esc(p.website||'Not found')}</div><div class="prx-fact"><b>Phone</b><br>${esc(p.phone||'Not found')}</div><div class="prx-fact"><b>Address</b><br>${esc(p.address||'Not found')}</div><div class="prx-fact"><b>Category</b><br>${esc(p.category||'Unclassified')}</div><div class="prx-fact"><b>Latitude</b><br>${esc(p.lat??'Not found')}</div><div class="prx-fact"><b>Longitude</b><br>${esc(p.lng??'Not found')}</div></div></section><section class="prx-card"><h3>Nine-factor acquisition score</h3><div class="prx-grid">${metrics}</div></section><section class="prx-card"><h3>Prospect opportunity report</h3><p>${esc(rep.summary||'')}</p><div class="prx-note"><b>Evidence safeguard:</b> public business attributes are enrichment signals. Revenue, retention, referral and economic gaps remain planning assumptions until verified with the business.</div><h4>Next actions</h4><ol class="prx-actions">${actions}</ol><p><a class="prx-btn" href="/marketing-revenue-system/?prospect_id=${esc(d.prospect_id||'')}#prospect-${esc(d.prospect_id||'')}">Open Prospect Action Center</a> <a class="prx-btn" href="/top-priority-businesses/?prospect=${esc(d.prospect_id||'')}#prospect-${esc(d.prospect_id||'')}">Open Top Priority List</a> <a class="prx-btn" href="/revenue-engine/?prospect_id=${esc(d.prospect_id||'')}">Open Revenue Engine</a> <a class="prx-btn" href="/revenue-engine-prospecting/?prospect_id=${esc(d.prospect_id||'')}">Open Prospecting</a></p></section>`;}
   f.addEventListener('submit',async e=>{e.preventDefault();btn.disabled=true;s.textContent='Researching, geocoding, scoring and ranking…';out.innerHTML='';try{const res=await fetch('<?php echo $endpoint; ?>',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':'<?php echo esc_js($nonce); ?>'},body:JSON.stringify({business_input:f.business_input.value.trim()})});const d=await res.json();if(!res.ok||!d.ok)throw new Error(d.message||'Analysis failed');render(d);s.textContent='Analysis complete.';}catch(err){s.textContent='';out.innerHTML='<div class="prx-error"><b>Analysis could not be completed.</b><br>'+esc(err.message)+'</div>';}finally{btn.disabled=false;}});
  })();</script>
  <?php return ob_get_clean();
 }
}
PR_Prospect_Enrichment::init();
