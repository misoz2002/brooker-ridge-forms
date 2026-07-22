<?php
define('ABSPATH', __DIR__.'/wordpress/');
$GLOBALS['brah_test_actions']=[];$GLOBALS['brah_test_filters']=[];$GLOBALS['brah_test_shortcodes']=[];
function add_action($hook,$callback,$priority=10,$accepted_args=1){$GLOBALS['brah_test_actions'][$hook]=[$callback,$priority,$accepted_args];}
function add_filter($hook,$callback,$priority=10,$accepted_args=1){$GLOBALS['brah_test_filters'][$hook]=[$callback,$priority,$accepted_args];}
function add_shortcode($tag,$callback){$GLOBALS['brah_test_shortcodes'][$tag]=$callback;}
function register_activation_hook($file,$callback){$GLOBALS['brah_test_activation']=[$file,$callback];}
function plugin_basename($file){return 'brooker-ridge-forms/brooker-ridge-forms.php';}
function is_admin(){return false;}

require dirname(__DIR__).'/brooker-ridge-forms.php';

$required_actions=['admin_post_nopriv_brah_submit_form','admin_post_nopriv_brah_portal_register','admin_post_nopriv_brah_portal_login','admin_post_brah_portal_request','admin_post_brah_portal_account_action'];
$required_shortcodes=['brooker_appointment_form','brooker_registration_form','brooker_client_portal'];
foreach($required_actions as $hook)if(empty($GLOBALS['brah_test_actions'][$hook])){fwrite(STDERR,"Missing action: {$hook}\n");exit(1);}
foreach($required_shortcodes as $tag)if(empty($GLOBALS['brah_test_shortcodes'][$tag])){fwrite(STDERR,"Missing shortcode: {$tag}\n");exit(1);}
if(empty($GLOBALS['brah_test_filters']['site_transient_update_plugins'])){fwrite(STDERR,"Missing stale updater guard.\n");exit(1);}
if(BRAH_Forms::VERSION!=='2.2.5'||!class_exists('BRAH_Client_Portal')){fwrite(STDERR,"Portal version or class mismatch.\n");exit(1);}
if(empty($GLOBALS['brah_test_actions']['wp_head'])||empty($GLOBALS['brah_test_actions']['wp_footer'])||empty($GLOBALS['brah_test_actions']['template_redirect'])||empty($GLOBALS['brah_test_filters']['the_content'])||empty($GLOBALS['brah_test_filters']['aioseo_description'])){fwrite(STDERR,"Missing homepage SEO hooks.\n");exit(1);}
$plugin='brooker-ridge-forms/brooker-ridge-forms.php';$stale=(object)['response'=>[$plugin=>(object)['new_version'=>'2.2.5']]];$newer=(object)['response'=>[$plugin=>(object)['new_version'=>'2.2.6']]];
if(isset(BRAH_Forms::suppress_stale_update($stale)->response[$plugin])||!isset(BRAH_Forms::suppress_stale_update($newer)->response[$plugin])){fwrite(STDERR,"Stale updater guard failed.\n");exit(1);}
$sample='<h1>Welcome to Brooker Ridge Animal Hospital – Trusted Veterinarian in Newmarket</h1><h4 class="et_pb_module_header"><span>Pet Dentistry</span></h4><h4 class="et_pb_module_header"><span>Pet Dentistry</span></h4>';
$clean=BRAH_Forms::clean_homepage_seo_output($sample);
if(strpos($clean,'Welcome to')!==false||substr_count($clean,'<h4 class="et_pb_module_header"><span>Pet Dentistry</span></h4>')!==1||strpos($clean,'brah-seo-module-heading')===false){fwrite(STDERR,"Homepage SEO cleanup failed.\n");exit(1);}
echo "Plugin load smoke test passed.\n";
