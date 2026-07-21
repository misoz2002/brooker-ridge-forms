<?php
/**
 * Plugin Name: Brooker Ridge Forms
 * Description: Subscription-free appointment and new-client forms for Brooker Ridge Animal Hospital.
 * Version: 2.1.6
 * Author: Brooker Ridge Animal Hospital
 * Update URI: https://github.com/misoz2002/brooker-ridge-forms
 */

if (!defined('ABSPATH')) exit;

final class BRAH_Forms {
    const VERSION = '2.1.6';
    const EMAIL = 'brah.reception@gmail.com'; // EDIT: form notification recipient.
    private static $homepage_contact_printed = false;

    public static function init() {
        add_shortcode('brooker_appointment_form', [__CLASS__, 'appointment']);
        add_shortcode('brooker_registration_form', [__CLASS__, 'registration']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'homepage_seo_assets']);
        add_action('wp_head', [__CLASS__, 'homepage_schema'], 20);
        add_action('wp_footer', [__CLASS__, 'homepage_footer_contact_block'], 5);
        add_filter('the_content', [__CLASS__, 'homepage_contact_block'], 8);
        add_filter('document_title_parts', [__CLASS__, 'homepage_title_parts'], 20);
        add_filter('pre_get_document_title', [__CLASS__, 'homepage_document_title'], 20);
        add_filter('aioseo_title', [__CLASS__, 'homepage_aioseo_title'], 20);
        add_filter('aioseo_description', [__CLASS__, 'homepage_aioseo_description'], 20);
        add_action('admin_post_nopriv_brah_submit_form', [__CLASS__, 'submit']);
        add_action('admin_post_brah_submit_form', [__CLASS__, 'submit']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'admin_settings']);
        add_action('admin_post_brah_export_csv', [__CLASS__, 'export_csv']);
        add_action('admin_post_brah_save_editor', [__CLASS__, 'save_editor']);
        add_action('init', [__CLASS__, 'register_submission_type']);
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check_for_update']);
        add_filter('site_transient_update_plugins', [__CLASS__, 'suppress_stale_update']);
        add_filter('plugins_api', [__CLASS__, 'plugin_information'], 20, 3);
        add_action('delete_site_transient_update_plugins', [__CLASS__, 'clear_release_cache']);
    }

    private static function release() {
        $cached=get_transient('brah_forms_github_release'); if($cached!==false)return $cached;
        $response=wp_remote_get('https://api.github.com/repos/misoz2002/brooker-ridge-forms/releases/latest',['timeout'=>10,'headers'=>['Accept'=>'application/vnd.github+json','User-Agent'=>'Brooker-Ridge-Forms/'.self::VERSION]]);
        if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)return false;
        $release=json_decode(wp_remote_retrieve_body($response),true); if(empty($release['tag_name']))return false; set_transient('brah_forms_github_release',$release,15*MINUTE_IN_SECONDS); return $release;
    }
    public static function clear_release_cache() { delete_transient('brah_forms_github_release'); }
    private static function release_package($release) {
        $version=ltrim((string)($release['tag_name']??''),'v'); if(!preg_match('/^\d+\.\d+\.\d+$/',$version))return '';
        $expected='brooker-ridge-forms-'.$version.'.zip'; foreach((array)($release['assets']??[]) as $asset)if(($asset['name']??'')===$expected)return $asset['browser_download_url']??''; return '';
    }
    public static function check_for_update($transient) {
        if(empty($transient->checked))return $transient; $release=self::release(); if(!$release)return $transient; $version=ltrim($release['tag_name'],'v'); $package=self::release_package($release); $plugin=plugin_basename(__FILE__);
        if($package&&version_compare(self::VERSION,$version,'<')){$transient->response[$plugin]=(object)['slug'=>'brooker-ridge-forms','plugin'=>$plugin,'new_version'=>$version,'url'=>'https://github.com/misoz2002/brooker-ridge-forms','package'=>$package,'tested'=>get_bloginfo('version'),'requires_php'=>'7.4'];}
        else{unset($transient->response[$plugin]);}
        return $transient;
    }
    public static function suppress_stale_update($transient) {
        if(!is_object($transient))return $transient;$plugin=plugin_basename(__FILE__);$version=(string)($transient->response[$plugin]->new_version??'');
        if($version&&!version_compare(self::VERSION,$version,'<'))unset($transient->response[$plugin]);return $transient;
    }
    public static function plugin_information($result,$action,$args) {
        if($action!=='plugin_information'||($args->slug??'')!=='brooker-ridge-forms')return $result; $release=self::release(); if(!$release)return $result; $version=ltrim($release['tag_name'],'v');
        return (object)['name'=>'Brooker Ridge Forms','slug'=>'brooker-ridge-forms','version'=>$version,'author'=>'Brooker Ridge Animal Hospital','homepage'=>'https://github.com/misoz2002/brooker-ridge-forms','download_link'=>self::release_package($release),'sections'=>['description'=>'Subscription-free public forms plus an optional, approval-gated client portal for appointment, refill, and food requests.','changelog'=>wp_kses_post(nl2br($release['body']??''))]];
    }

    public static function register_submission_type() {
        register_post_type('brah_submission',['labels'=>['name'=>'Form Submissions'],'public'=>false,'show_ui'=>false,'supports'=>['title','editor'],'capability_type'=>'post']);
    }

    private static function defaults() {
        return ['required'=>['owner_first','owner_last','phone','email','existing_client','pet_name','species','appointment_type','reason','accuracy','request_only'], 'navy'=>'#082f5f', 'green'=>'#79c542', 'max_width'=>920, 'radius'=>18, 'google_webhook'=>'', 'google_secret'=>''];
    }

    private static function settings() { $s=wp_parse_args(get_option('brah_forms_settings', []), self::defaults()); $s['required']=array_values(array_intersect((array)$s['required'],array_keys(self::editable_fields()))); return $s; }
    private static function is_required($name, $default=false) { $s=self::settings(); return in_array($name, (array)$s['required'], true); }
    private static function req($name, $default=false) { return self::is_required($name,$default) ? ' required' : ''; }
    private static function star($name, $default=false) { return self::is_required($name,$default) ? ' <b>*</b>' : ''; }

    public static function admin_menu() {
        add_options_page('Brooker Ridge Forms','Brooker Ridge Forms','manage_options','brah-forms',[__CLASS__,'admin_page']);
        add_submenu_page('options-general.php','Brooker Ridge Submissions','Brooker Ridge Submissions','manage_options','brah-submissions',[__CLASS__,'submissions_page']);
        add_submenu_page('options-general.php','Brooker Ridge Form Editor','Brooker Ridge Form Editor','manage_options','brah-form-editor',[__CLASS__,'editor_page']);
    }
    public static function admin_settings() { register_setting('brah_forms_group','brah_forms_settings',['sanitize_callback'=>[__CLASS__,'sanitize_settings']]); }
    public static function sanitize_settings($v) {
        $fields=array_keys(self::editable_fields()); $required=array_values(array_intersect($fields,(array)($v['required']??[])));
        return ['required'=>$required,'navy'=>sanitize_hex_color($v['navy']??'')?:'#082f5f','green'=>sanitize_hex_color($v['green']??'')?:'#79c542','max_width'=>min(1400,max(600,absint($v['max_width']??920))),'radius'=>min(40,max(0,absint($v['radius']??18))),'google_webhook'=>esc_url_raw($v['google_webhook']??''),'google_secret'=>sanitize_text_field($v['google_secret']??'')];
    }
    private static function editable_fields() { return [
        'owner_first'=>'Owner first name','owner_last'=>'Owner last name','phone'=>'Cell phone','email'=>'Email address','street'=>'Street address','unit'=>'Unit/suite','city'=>'City','province'=>'Province','postal_code'=>'Postal code','existing_client'=>'Existing client','regular_vet'=>'Regular veterinarian','pet_name'=>'Pet name','species'=>'Species','breed'=>'Breed','gender'=>'Gender','altered'=>'Spayed/neutered','age'=>'Age or birth date','colour'=>'Colour','appointment_type'=>'Appointment type','reason'=>'Main reason','description'=>'Description','issue_started'=>'Issue start date','preferred_date'=>'Preferred date','preferred_time'=>'Preferred time','accuracy'=>'Accuracy confirmation','request_only'=>'Appointment acknowledgement'
    ]; }

    private static function field($id,$label,$type='text',$section='',$required=false,$width='half',$options='',$condition_field='',$condition_value='') {
        return compact('id','label','type','section','required','width','options','condition_field','condition_value')+['enabled'=>true,'placeholder'=>'','help'=>''];
    }
    private static function default_schema($type) {
        $owner=[
            self::field('owner_first','Owner First Name','text','Owner Information',true),self::field('owner_last','Owner Last Name','text','Owner Information',true),self::field('phone','Cell Phone Number','tel','Owner Information',true),self::field('email','Email Address','email','Owner Information',true),
            self::field('street','Street Address','text','Owner Information',false,'full'),self::field('unit','Unit/Suite','text','Owner Information'),self::field('city','City','text','Owner Information'),self::field('province','Province (2 letters)','text','Owner Information'),self::field('postal_code','Postal Code','text','Owner Information'),self::field('existing_client','Existing Client of Brooker Ridge Animal Hospital?','select','Owner Information',true,'half','Yes|No|Unsure'),self::field('regular_vet','Regular Veterinarian','text','Owner Information')
        ];
        $pet=[
            self::field('pet_name','Pet’s Name','text','Pet Information',true),self::field('species','Species','select','Pet Information',true,'half','Dog|Cat'),self::field('breed','Breed (if known)','text','Pet Information'),self::field('gender','Gender','select','Pet Information',false,'half','Male|Female|Unknown'),self::field('altered','Spayed/Neutered?','select','Pet Information',false,'half','Yes|No|Unknown'),self::field('age','Age or Date of Birth (if known)','text','Pet Information'),self::field('colour','Colour','text','Pet Information'),self::field('photo','Patient File Photo (optional)','file','Pet Information'),self::field('records','Medical/Vaccination Records (optional)','file','Pet Information',false,'full')
        ];
        if($type==='appointment') return array_merge($owner,$pet,[
            self::field('appointment_type','Type of Appointment Requested','select','Appointment Request',true,'half','Wellness exam|Vaccines|Sick visit|Recheck/follow-up|Nail trim|Ear/skin issue|Limping/pain|Vomiting/diarrhea|Dental concern|Surgery/dental inquiry|Medication refill consultation'),self::field('reason','Main Reason for Appointment','text','Appointment Request',true),self::field('description','Description of Symptoms or Concerns','textarea','Appointment Request',false,'full'),self::field('issue_started','When Did the Issue Start?','text','Appointment Request'),self::field('preferred_date','Preferred Appointment Date','date','Appointment Request'),self::field('preferred_time','Preferred Time of Day','select','Appointment Request',false,'half','Afternoon|Evening|First available'),self::field('symptoms','Symptoms That Apply','checkboxes','Appointment Request',false,'full','Abnormal eating|Abnormal drinking|Abnormal pooping|Abnormal peeing|Abnormal behaviour|Lethargic|Vomiting|Diarrhea|Sneezing|Coughing|Hiding|None of the above'),self::field('accuracy','Information Accuracy Confirmation','checkbox','Final Confirmations',true,'full','I confirm that the information provided is accurate to the best of my knowledge.'),self::field('request_only','Appointment Request Acknowledgement','checkbox','Final Confirmations',true,'full','I understand this is only a request and is not confirmed until the clinic contacts me.'),self::field('sms_consent','Text/SMS Consent (optional)','checkbox','Final Confirmations',false,'full','I consent to receiving appointment and pickup reminders by text/SMS. STOP to opt out.')
        ]);
        $second=[]; foreach($pet as $f){$f['id']='pet2_'.$f['id'];$f['section']='Pet Information 2';$f['condition_field']='another_pet';$f['condition_value']='Yes';$f['required']=false;$second[]=$f;}
        return array_merge($owner,$pet,[self::field('reason','Main Reason for Appointment','textarea','Reason for Visit'),self::field('description','Description of Symptoms or Concerns','textarea','Reason for Visit',false,'full'),self::field('symptoms','Symptoms That Apply','checkboxes','Reason for Visit',false,'full','Abnormal eating|Abnormal drinking|Abnormal pooping|Abnormal peeing|Abnormal behaviour|Lethargic|Vomiting|Diarrhea|Sneezing|Coughing|Hiding|None of the above'),self::field('another_pet','Register Another Pet?','checkbox','Additional Pet',false,'full','Yes')],$second,[self::field('accuracy','Information Accuracy Confirmation','checkbox','Final Confirmations',true,'full','I confirm that the information provided is accurate to the best of my knowledge.'),self::field('sms_consent','Text/SMS Consent (optional)','checkbox','Final Confirmations',false,'full','I consent to receiving appointment and pickup reminders by text/SMS. STOP to opt out.')]);
    }
    private static function schema($type) { $all=get_option('brah_forms_schema',[]); return !empty($all[$type])?$all[$type]:self::default_schema($type); }
    public static function admin_page() { if(!current_user_can('manage_options'))return; $s=self::settings(); ?>
      <div class="wrap"><h1>Brooker Ridge Forms</h1><p>Adjust shared appearance and integrations here. Use <a href="<?php echo esc_url(admin_url('options-general.php?page=brah-form-editor')); ?>">Brooker Ridge Form Editor</a> to manage fields, required status, choices, layout, and conditional logic.</p>
      <form method="post" action="options.php"><?php settings_fields('brah_forms_group'); ?>
      <?php foreach((array)$s['required'] as $legacy_required): ?><input type="hidden" name="brah_forms_settings[required][]" value="<?php echo esc_attr($legacy_required); ?>"><?php endforeach; ?>
      <h2>Formatting</h2><table class="form-table"><tr><th>Header navy</th><td><input type="color" name="brah_forms_settings[navy]" value="<?php echo esc_attr($s['navy']); ?>"></td></tr><tr><th>Accent green</th><td><input type="color" name="brah_forms_settings[green]" value="<?php echo esc_attr($s['green']); ?>"></td></tr><tr><th>Maximum width</th><td><input type="number" min="600" max="1400" name="brah_forms_settings[max_width]" value="<?php echo esc_attr($s['max_width']); ?>"> px</td></tr><tr><th>Corner roundness</th><td><input type="number" min="0" max="40" name="brah_forms_settings[radius]" value="<?php echo esc_attr($s['radius']); ?>"> px</td></tr></table>
      <h2>Google Sheets Connection</h2><p>Enter the deployed Google Apps Script Web App URL and the matching private secret. Successful submissions will be added to the clinic spreadsheet automatically.</p><table class="form-table"><tr><th>Web App URL</th><td><input class="regular-text" type="url" name="brah_forms_settings[google_webhook]" value="<?php echo esc_attr($s['google_webhook']); ?>" placeholder="https://script.google.com/macros/s/.../exec"></td></tr><tr><th>Private secret</th><td><input class="regular-text" type="password" autocomplete="new-password" name="brah_forms_settings[google_secret]" value="<?php echo esc_attr($s['google_secret']); ?>"></td></tr></table>
      <?php submit_button('Save Form Settings'); ?></form><hr><p><strong>Shortcodes:</strong> <code>[brooker_appointment_form]</code> and <code>[brooker_registration_form]</code></p></div>
    <?php }

    public static function editor_page() {
        if(!current_user_can('manage_options'))return; wp_enqueue_script('jquery-ui-sortable');
        $type=(isset($_GET['form'])&&$_GET['form']==='registration')?'registration':'appointment'; $fields=self::schema($type); $types=['text'=>'Text','email'=>'Email','tel'=>'Telephone','date'=>'Date','textarea'=>'Long text','select'=>'Dropdown','radio'=>'Radio buttons','checkbox'=>'Single checkbox','checkboxes'=>'Checkbox group','file'=>'File upload']; ?>
        <div class="wrap"><h1>Brooker Ridge Form Editor</h1><p>Edit the fields below, drag rows using the ↕ handle, and save. Changes appear on the live form immediately.</p>
        <nav class="nav-tab-wrapper"><a class="nav-tab <?php echo $type==='appointment'?'nav-tab-active':''; ?>" href="?page=brah-form-editor&form=appointment">Appointment Form</a><a class="nav-tab <?php echo $type==='registration'?'nav-tab-active':''; ?>" href="?page=brah-form-editor&form=registration">Registration Form</a></nav>
        <?php if(isset($_GET['saved'])):?><div class="notice notice-success"><p>Form saved.</p></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="brah_save_editor"><input type="hidden" name="form_type" value="<?php echo esc_attr($type); ?>"><?php wp_nonce_field('brah_save_editor_'.$type); ?>
        <table class="widefat striped" id="brah-field-editor"><thead><tr><th style="width:28px"></th><th>Field</th><th>Type</th><th>Section & layout</th><th>Choices</th><th>Conditional logic</th><th>Settings</th></tr></thead><tbody>
        <?php foreach($fields as $i=>$f): ?><tr class="brah-editor-row"><td class="brah-handle" style="cursor:move;font-size:20px">↕</td><td><input type="hidden" name="fields[<?php echo $i; ?>][id]" value="<?php echo esc_attr($f['id']); ?>"><label>Label<br><input class="regular-text" name="fields[<?php echo $i; ?>][label]" value="<?php echo esc_attr($f['label']); ?>"></label><br><label>Help text<br><input name="fields[<?php echo $i; ?>][help]" value="<?php echo esc_attr($f['help']??''); ?>"></label><br><label>Placeholder<br><input name="fields[<?php echo $i; ?>][placeholder]" value="<?php echo esc_attr($f['placeholder']??''); ?>"></label><br><small>ID: <code><?php echo esc_html($f['id']); ?></code></small></td>
        <td><select name="fields[<?php echo $i; ?>][type]"><?php foreach($types as $v=>$label): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($f['type'],$v); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></td>
        <td><label>Section<br><input name="fields[<?php echo $i; ?>][section]" value="<?php echo esc_attr($f['section']); ?>"></label><br><label>Width<br><select name="fields[<?php echo $i; ?>][width]"><option value="half" <?php selected($f['width'],'half'); ?>>Half</option><option value="full" <?php selected($f['width'],'full'); ?>>Full</option></select></label></td>
        <td><textarea name="fields[<?php echo $i; ?>][options]" rows="5" placeholder="One|Choice|Per|Line"><?php echo esc_textarea($f['options']); ?></textarea><p class="description">Separate choices with |</p></td>
        <td><label>Show only when<br><select name="fields[<?php echo $i; ?>][condition_field]"><option value="">Always show</option><?php foreach($fields as $candidate): if($candidate['id']===$f['id'])continue; ?><option value="<?php echo esc_attr($candidate['id']); ?>" <?php selected($f['condition_field'],$candidate['id']); ?>><?php echo esc_html($candidate['label']); ?></option><?php endforeach; ?></select></label><br><label>Equals<br><input name="fields[<?php echo $i; ?>][condition_value]" value="<?php echo esc_attr($f['condition_value']); ?>"></label></td>
        <td><input type="hidden" name="fields[<?php echo $i; ?>][enabled]" value="0"><label><input type="checkbox" name="fields[<?php echo $i; ?>][enabled]" value="1" <?php checked(!empty($f['enabled'])); ?>> Visible</label><br><input type="hidden" name="fields[<?php echo $i; ?>][required]" value="0"><label><input type="checkbox" name="fields[<?php echo $i; ?>][required]" value="1" <?php checked(!empty($f['required'])); ?>> Required</label><br><button type="button" class="button-link-delete brah-delete-field">Delete</button></td></tr><?php endforeach; ?>
        </tbody></table><p><button type="button" class="button" id="brah-add-field">Add Field</button> <?php submit_button('Save Form', 'primary', 'submit', false); ?> <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=brah_save_editor&reset=1&form_type='.$type),'brah_save_editor_'.$type)); ?>" onclick="return confirm('Reset this form to its original fields?')">Reset Form</a></p></form></div>
        <script>jQuery(function($){var body=$('#brah-field-editor tbody');body.sortable({handle:'.brah-handle'});body.on('click','.brah-delete-field',function(){if(confirm('Delete this field?'))$(this).closest('tr').remove()});$('#brah-add-field').on('click',function(){var row=body.find('tr:last').clone(false),i='new'+Date.now();row.find('[name]').each(function(){this.name=this.name.replace(/fields\[[^\]]+\]/,'fields['+i+']')});row.find('input[type=text],textarea').val('');row.find('input[name$="[id]"]').val('custom_'+Date.now());row.find('input[name$="[label]"]').val('New Field');row.find('input[type=checkbox]').prop('checked',false);row.find('select[name$="[type]"]').val('text');row.find('select[name$="[width]"]').val('half');row.find('select[name$="[condition_field]"]').val('');row.find('code').text('New ID assigned when saved');body.append(row);});});</script>
    <?php }

    public static function save_editor() {
        if(!current_user_can('manage_options'))wp_die('Not authorized.'); $type=($_REQUEST['form_type']??'')==='registration'?'registration':'appointment'; check_admin_referer('brah_save_editor_'.$type);
        $all=get_option('brah_forms_schema',[]); if(!empty($_GET['reset'])){$all[$type]=self::default_schema($type);}else{$clean=[];foreach((array)($_POST['fields']??[]) as $f){$id=preg_replace('/[^a-z0-9_]/','',strtolower($f['id']??''));if(!$id)continue;$allowed=['text','email','tel','date','textarea','select','radio','checkbox','checkboxes','file'];$clean[]=['id'=>$id,'label'=>sanitize_text_field($f['label']??$id),'type'=>in_array($f['type']??'',$allowed,true)?$f['type']:'text','section'=>sanitize_text_field($f['section']??''),'required'=>!empty($f['required']),'enabled'=>!empty($f['enabled']),'width'=>($f['width']??'')==='full'?'full':'half','options'=>sanitize_textarea_field($f['options']??''),'condition_field'=>sanitize_key($f['condition_field']??''),'condition_value'=>sanitize_text_field($f['condition_value']??''),'placeholder'=>sanitize_text_field($f['placeholder']??''),'help'=>sanitize_text_field($f['help']??'')];}$all[$type]=$clean;}
        update_option('brah_forms_schema',$all,false); wp_safe_redirect(admin_url('options-general.php?page=brah-form-editor&form='.$type.'&saved=1'));exit;
    }

    public static function submissions_page() {
        if(!current_user_can('manage_options'))return;
        $rows=get_posts(['post_type'=>'brah_submission','post_status'=>'private','numberposts'=>100,'orderby'=>'date','order'=>'DESC']); ?>
        <div class="wrap"><h1>Brooker Ridge Form Submissions</h1><p>Successful submissions are saved privately in WordPress and can be downloaded as a spreadsheet-compatible CSV file.</p>
        <p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=brah_export_csv'),'brah_export_csv')); ?>">Download CSV</a></p>
        <table class="widefat striped"><thead><tr><th>Date</th><th>Form</th><th>Owner</th><th>Pet</th><th>Email</th><th>Phone</th></tr></thead><tbody>
        <?php if(!$rows): ?><tr><td colspan="6">No submissions have been recorded yet.</td></tr><?php endif; ?>
        <?php foreach($rows as $row): $d=json_decode($row->post_content,true); ?><tr><td><?php echo esc_html(get_the_date('Y-m-d g:i a',$row)); ?></td><td><?php echo esc_html($d['form_type']??''); ?></td><td><?php echo esc_html(trim(($d['owner_first']??'').' '.($d['owner_last']??''))); ?></td><td><?php echo esc_html($d['pet_name']??''); ?></td><td><?php echo esc_html($d['email']??''); ?></td><td><?php echo esc_html($d['phone']??''); ?></td></tr><?php endforeach; ?>
        </tbody></table><p><em>Only WordPress administrators can view or export this information.</em></p></div>
    <?php }

    public static function export_csv() {
        if(!current_user_can('manage_options')||!check_admin_referer('brah_export_csv'))wp_die('Not authorized.');
        $posts=get_posts(['post_type'=>'brah_submission','post_status'=>'private','numberposts'=>-1,'orderby'=>'date','order'=>'ASC']); $data=[]; $keys=['submitted_at'];
        foreach($posts as $p){$row=json_decode($p->post_content,true)?:[];$row=['submitted_at'=>get_the_date('c',$p)]+$row;$data[]=$row;$keys=array_values(array_unique(array_merge($keys,array_keys($row))));}
        nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="brooker-ridge-submissions-'.gmdate('Y-m-d').'.csv"');
        $out=fopen('php://output','w'); fputcsv($out,$keys); foreach($data as $row){$line=[];foreach($keys as $k){$v=$row[$k]??'';$line[]=is_array($v)?implode('; ',$v):$v;}fputcsv($out,$line);}fclose($out);exit;
    }

    public static function assets() {
        wp_register_style('brah-forms', plugins_url('assets/forms.css', __FILE__), [], self::VERSION);
        wp_register_style('brah-seo', plugins_url('assets/seo.css', __FILE__), [], self::VERSION);
        wp_register_script('brah-forms', plugins_url('assets/forms.js', __FILE__), [], self::VERSION, true);
    }

    private static function is_public_front_page() {
        if(is_admin())return false;
        return function_exists('is_front_page')&&is_front_page();
    }

    private static function homepage_seo_title() {
        return 'Brooker Ridge Animal Hospital | Veterinarian in Newmarket';
    }

    private static function homepage_seo_description() {
        return 'Brooker Ridge Animal Hospital in Newmarket provides veterinary care, surgery, dentistry, vaccinations, diagnostics, and urgent care for pets.';
    }

    public static function homepage_seo_assets() {
        if(self::is_public_front_page())wp_enqueue_style('brah-seo');
    }

    public static function homepage_title_parts($parts) {
        if(!self::is_public_front_page())return $parts;
        $parts['title']='Brooker Ridge Animal Hospital';
        $parts['tagline']='Veterinarian in Newmarket';
        return $parts;
    }

    public static function homepage_document_title($title) {
        return self::is_public_front_page()?self::homepage_seo_title():$title;
    }

    public static function homepage_aioseo_title($title) {
        return self::is_public_front_page()?self::homepage_seo_title():$title;
    }

    public static function homepage_aioseo_description($description) {
        return self::is_public_front_page()?self::homepage_seo_description():$description;
    }

    public static function homepage_schema() {
        if(!self::is_public_front_page())return;
        $schema=[
            '@context'=>'https://schema.org',
            '@type'=>'VeterinaryCare',
            '@id'=>home_url('/#veterinary-care'),
            'name'=>'Brooker Ridge Animal Hospital',
            'url'=>home_url('/'),
            'image'=>home_url('/wp-content/uploads/2026/07/cat-dog-image-for-slider-optimized.jpg'),
            'telephone'=>'+1-905-898-1010',
            'description'=>self::homepage_seo_description(),
            'address'=>[
                '@type'=>'PostalAddress',
                'streetAddress'=>'Unit 107, 525 Brooker Ridge',
                'addressLocality'=>'Newmarket',
                'addressRegion'=>'ON',
                'postalCode'=>'L3X 2M2',
                'addressCountry'=>'CA',
            ],
            'areaServed'=>[
                ['@type'=>'City','name'=>'Newmarket'],
                ['@type'=>'City','name'=>'Aurora'],
                ['@type'=>'City','name'=>'Richmond Hill'],
            ],
            'medicalSpecialty'=>'Veterinary medicine',
            'priceRange'=>'$$',
            'sameAs'=>['https://facebook.com/vetsnewmarket','https://x.com/branimalh'],
        ];
        echo "\n".'<script type="application/ld+json">'.wp_json_encode($schema).'</script>'."\n";
    }

    public static function homepage_contact_block($content) {
        if(!self::is_public_front_page()||strpos($content,'brah-seo-contact')!==false)return $content;
        self::$homepage_contact_printed=true;
        return self::homepage_contact_markup().$content;
    }

    public static function homepage_footer_contact_block() {
        if(!self::is_public_front_page()||self::$homepage_contact_printed)return;
        self::$homepage_contact_printed=true;
        echo self::homepage_contact_markup();
    }

    private static function homepage_contact_markup() {
        return '<section class="brah-seo-contact" aria-label="Brooker Ridge Animal Hospital contact information"><h2>Newmarket Veterinary Clinic Location</h2><address><strong>Brooker Ridge Animal Hospital</strong><br>Unit 107, 525 Brooker Ridge<br>Newmarket, Ontario L3X 2M2<br>Phone: <a href="tel:+19058981010">905-898-1010</a></address></section>';
    }

    private static function start($type, $title, $intro) {
        wp_enqueue_style('brah-forms'); wp_enqueue_script('brah-forms');
        $token = base64_encode(wp_json_encode(['t'=>time(),'f'=>$type]));
        $sig = hash_hmac('sha256', $token, wp_salt('nonce'));
        ob_start();
        $status = isset($_GET['brah_form']) ? sanitize_key($_GET['brah_form']) : '';
        if ($status === 'success') echo '<div class="brah-notice success" role="status">Thank you. Your form was sent successfully. Our team will contact you shortly.</div>';
        if ($status === 'error') { $reasons=['security'=>'The form security check expired. Please refresh the page and try again.','captcha'=>'Please confirm that you are human and try again.','required'=>'A required field is missing. Please review fields marked with an asterisk.','rate'=>'Please wait 30 seconds before sending the form again.']; $reason=sanitize_key($_GET['brah_reason']??''); echo '<div class="brah-notice error" role="alert">'.esc_html($reasons[$reason]??'We could not send the form. Please review the required fields or call 905-898-1010.').'</div>'; }
        ?>
        <?php $style=self::settings(); ?>
        <form class="brah-form" novalidate style="--navy:<?php echo esc_attr($style['navy']); ?>;--green:<?php echo esc_attr($style['green']); ?>;max-width:<?php echo absint($style['max_width']); ?>px;border-radius:<?php echo absint($style['radius']); ?>px" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <header><span>Brooker Ridge Animal Hospital</span><h1><?php echo esc_html($title); ?></h1><p><?php echo esc_html($intro); ?></p></header>
          <input type="hidden" name="action" value="brah_submit_form"><input type="hidden" name="form_type" value="<?php echo esc_attr($type); ?>">
          <input type="hidden" name="captcha_token" value="<?php echo esc_attr($token); ?>"><input type="hidden" name="captcha_sig" value="<?php echo esc_attr($sig); ?>">
          <?php wp_nonce_field('brah_form_'.$type, 'brah_nonce'); ?>
          <div class="brah-trap" aria-hidden="true"><label>Leave this blank<input name="website" tabindex="-1" autocomplete="off"></label></div>
        <?php return ob_get_clean();
    }

    private static function owner_fields() { ob_start(); ?>
      <section><h2>Owner Information</h2><div class="grid two">
        <?php self::input('owner_first','Owner First Name',true); self::input('owner_last','Owner Last Name',true); self::input('phone','Cell Phone Number',true,'tel'); self::input('email','Email Address',true,'email'); ?>
        <label class="full">Street Address<?php echo self::star('street'); ?><input name="street" autocomplete="address-line1"<?php echo self::req('street'); ?>></label>
        <label>Unit/Suite<?php echo self::star('unit'); ?><input name="unit" autocomplete="address-line2"<?php echo self::req('unit'); ?>></label>
        <label>City<?php echo self::star('city'); ?><input name="city" autocomplete="address-level2"<?php echo self::req('city'); ?>></label>
        <label>Province (2 letters)<?php echo self::star('province'); ?><input name="province" autocomplete="address-level1" maxlength="2" pattern="[A-Za-z]{2}" placeholder="ON" style="text-transform:uppercase"<?php echo self::req('province'); ?>></label>
        <label>Postal Code<?php echo self::star('postal_code'); ?><input name="postal_code" autocomplete="postal-code" maxlength="7" pattern="[A-Za-z][0-9][A-Za-z][ -]?[0-9][A-Za-z][0-9]" placeholder="L3X 2M2" style="text-transform:uppercase"<?php echo self::req('postal_code'); ?>></label>
        <label>Existing client?<?php echo self::star('existing_client',true); ?><select name="existing_client"<?php echo self::req('existing_client',true); ?>><option value="">Choose one</option><option>Yes</option><option>No</option><option>Unsure</option></select></label>
        <label>Regular veterinarian<?php echo self::star('regular_vet'); ?><input name="regular_vet"<?php echo self::req('regular_vet'); ?>></label>
      </div></section>
    <?php return ob_get_clean(); }

    private static function input($name,$label,$required=false,$type='text') { $base=preg_replace('/^pet2_/','',$name); $needed=self::is_required($base,$required); printf('<label>%s%s<input type="%s" name="%s" %s></label>',esc_html($label),$needed?' <b>*</b>':'',esc_attr($type),esc_attr($name),$needed?'required':''); }

    private static function pet_fields($prefix='', $second=false) { ob_start(); ?>
      <section class="pet-section<?php echo $second?' second-pet':''; ?>"><h2><?php echo $second?'Pet Information 2':'Pet Information'; ?></h2><div class="grid two">
        <?php self::input($prefix.'pet_name','Pet’s Name',!$second); self::input($prefix.'breed','Breed (if known)'); ?>
        <label>Species<?php echo self::star('species',!$second); ?><select name="<?php echo esc_attr($prefix); ?>species"<?php echo self::req('species',!$second); ?>><option value="">Choose one</option><option>Dog</option><option>Cat</option></select></label>
        <label>Gender<?php echo self::star('gender'); ?><select name="<?php echo esc_attr($prefix); ?>gender"<?php echo self::req('gender'); ?>><option value="">Choose one</option><option>Male</option><option>Female</option><option>Unknown</option></select></label>
        <label>Spayed/Neutered?<?php echo self::star('altered'); ?><select name="<?php echo esc_attr($prefix); ?>altered"<?php echo self::req('altered'); ?>><option value="">Choose one</option><option>Yes</option><option>No</option><option>Unknown</option></select></label>
        <?php self::input($prefix.'age','Age or Date of Birth (if known)'); self::input($prefix.'colour','Colour'); ?>
        <label>Patient file photo (optional)<input type="file" name="<?php echo esc_attr($prefix); ?>photo" accept="image/jpeg,image/png,image/webp"></label>
        <label class="full">Medical/vaccination records (optional)<input type="file" name="<?php echo esc_attr($prefix); ?>records[]" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"></label>
      </div></section>
    <?php return ob_get_clean(); }

    private static function concerns() { $items=['Abnormal eating','Abnormal drinking','Abnormal pooping','Abnormal peeing','Abnormal behaviour','Lethargic','Vomiting','Diarrhea','Sneezing','Coughing','Hiding','None of the above']; ob_start(); ?>
      <fieldset><legend>Please check any that apply</legend><div class="checks"><?php foreach($items as $i) printf('<label><input type="checkbox" name="symptoms[]" value="%s"> %s</label>',esc_attr($i),esc_html($i)); ?></div></fieldset>
    <?php return ob_get_clean(); }

    private static function end($type) { ob_start(); ?>
      <section><h2>Human Verification</h2>
        <label class="captcha human-check"><input type="checkbox" name="human_confirmed" value="1" required> <span>I’m human</span></label>
      </section><button type="submit">Submit <?php echo $type==='appointment'?'Appointment Request':'Registration'; ?></button><p class="privacy">Your information is sent securely to Brooker Ridge Animal Hospital and is not used for marketing.</p></form>
    <?php return ob_get_clean(); }

    private static function render_field($f) {
        if(empty($f['enabled']))return ''; $id=sanitize_key($f['id']); $required=!empty($f['required']); $options=array_values(array_filter(array_map('trim',explode('|',$f['options']??'')),'strlen'));
        $conditional=!empty($f['condition_field']); $disabled=$conditional?' disabled':'';
        $attrs=' name="'.esc_attr($id).'"'.($required?' required':'').$disabled.' placeholder="'.esc_attr($f['placeholder']??'').'"';
        if($id==='province')$attrs.=' maxlength="2" pattern="[A-Za-z]{2}" style="text-transform:uppercase"';
        if($id==='postal_code')$attrs.=' maxlength="7" pattern="[A-Za-z][0-9][A-Za-z][ -]?[0-9][A-Za-z][0-9]" style="text-transform:uppercase"';
        $condition=$conditional?' hidden data-condition-field="'.esc_attr($f['condition_field']).'" data-condition-value="'.esc_attr($f['condition_value']).'"':'';
        $html='<div class="brah-field '.(($f['width']??'half')==='full'?'full':'').'"'.$condition.'><label>'.esc_html($f['label']).($required?' <b>*</b>':''); $type=$f['type']??'text';
        if($type==='textarea')$html.='<textarea rows="5"'.$attrs.'></textarea>';
        elseif($type==='select'){$html.='<select'.$attrs.'><option value="">Choose one</option>';foreach($options as $o)$html.='<option value="'.esc_attr($o).'">'.esc_html($o).'</option>';$html.='</select>';}
        elseif($type==='radio'||$type==='checkboxes'){$html.='</label><div class="checks">';foreach($options as $o){$name=$type==='checkboxes'?$id.'[]':$id;$html.='<label><input type="'.($type==='radio'?'radio':'checkbox').'" name="'.esc_attr($name).'" value="'.esc_attr($o).'"'.($required?' required':'').$disabled.'> '.esc_html($o).'</label>';}$html.='</div>';}
        elseif($type==='checkbox'){$value=$options[0]??'Yes';$html.='</label><label class="checkline"><input type="checkbox" name="'.esc_attr($id).'" value="'.esc_attr($value).'"'.($required?' required':'').$disabled.'> '.esc_html($value).'</label>';}
        elseif($type==='file')$html.='<input type="file" name="'.esc_attr($id).'[]" multiple'.($required?' required':'').$disabled.'>';
        else $html.='<input type="'.esc_attr(in_array($type,['text','email','tel','date'],true)?$type:'text').'"'.$attrs.'>';
        if(!in_array($type,['radio','checkboxes','checkbox'],true))$html.='</label>'; if(!empty($f['help']))$html.='<p class="field-help">'.esc_html($f['help']).'</p>'; return $html.'</div>';
    }

    private static function render_schema($type,$title,$intro) {
        $html=self::start($type,$title,$intro); $groups=[]; foreach(self::schema($type) as $f){if(empty($f['enabled']))continue;$groups[$f['section']?:'Form'][]=$f;}
        foreach($groups as $section=>$fields){$cf=$fields[0]['condition_field']??'';$cv=$fields[0]['condition_value']??'';foreach($fields as $f)if(($f['condition_field']??'')!==$cf||($f['condition_value']??'')!==$cv){$cf='';$cv='';break;}$cond=$cf?' hidden data-condition-field="'.esc_attr($cf).'" data-condition-value="'.esc_attr($cv).'"':'';$html.='<section'.$cond.'><h2>'.esc_html($section).'</h2><div class="grid two">';foreach($fields as $f)$html.=self::render_field($f);$html.='</div></section>';}
        return $html.self::end($type);
    }
    private static function condition_met($f,$data) { if(empty($f['condition_field']))return true;$actual=$data[$f['condition_field']]??'';if(is_array($actual))return in_array($f['condition_value'],$actual,true);return (string)$actual===(string)$f['condition_value']; }
    private static function log_event($event,$context=[]) {
        if(!defined('WP_DEBUG_LOG')||!WP_DEBUG_LOG)return;
        $safe=['event'=>sanitize_key($event)]; foreach(['form_type','submission_id','email_delivery','google_delivery','reason'] as $key)if(isset($context[$key]))$safe[$key]=sanitize_text_field((string)$context[$key]);
        error_log('Brooker Ridge Forms '.wp_json_encode($safe));
    }

    public static function appointment() {
        return self::render_schema('appointment','Request an Appointment','Complete this form and our team will contact you to confirm availability.');
    }

    public static function registration() {
        return self::render_schema('registration','New Client/Pet Registration','Tell us about you and your pet so our team can prepare your patient file.');
    }

    public static function submit() {
        $type=sanitize_key($_POST['form_type']??''); $back=wp_get_referer()?:home_url('/');
        $fail=function($reason='')use($back,$type){self::log_event('submission_rejected',['form_type'=>$type,'reason'=>$reason]);wp_safe_redirect(add_query_arg(['brah_form'=>'error','brah_reason'=>sanitize_key($reason)],remove_query_arg(['brah_form','brah_reason'],$back)));exit;};
        if(!in_array($type,['appointment','registration'],true)||!wp_verify_nonce($_POST['brah_nonce']??'','brah_form_'.$type)||!empty($_POST['website'])) $fail('security');
        $token=sanitize_text_field($_POST['captcha_token']??''); $sig=sanitize_text_field($_POST['captcha_sig']??'');
        if(!hash_equals(hash_hmac('sha256',$token,wp_salt('nonce')),$sig)) $fail('security');
        $decoded=base64_decode($token,true); $cap=$decoded===false?null:json_decode($decoded,true); if(!is_array($cap)) $fail('captcha');
        $age=time()-intval($cap['t']??0); if(($cap['f']??'')!==$type||$age<3||$age>7200||($_POST['human_confirmed']??'')!=='1') $fail('captcha');
        foreach(self::schema($type) as $f){if(empty($f['enabled'])||empty($f['required'])||!self::condition_met($f,$_POST))continue;$id=$f['id'];if(($f['type']??'')==='file'){if(empty($_FILES[$id]['name'][0]))$fail('required');}elseif(empty($_POST[$id]))$fail('required');}
        $ip=hash('sha256',($_SERVER['REMOTE_ADDR']??'').wp_salt()); if(get_transient('brah_'.$ip))$fail('rate');
        $skip=['action','brah_nonce','captcha_token','captcha_sig','human_confirmed','website']; $lines=[]; $submission=['submitted_at'=>current_time('c')];
        foreach($_POST as $k=>$v){if(in_array($k,$skip,true))continue;$clean=is_array($v)?array_map('sanitize_text_field',$v):sanitize_textarea_field(wp_unslash($v));$submission[sanitize_key($k)]=$clean;$label=ucwords(str_replace('_',' ',$k));$value=is_array($clean)?implode(', ',$clean):$clean;$lines[]="$label: $value";}
        $attachments=[]; require_once ABSPATH.'wp-admin/includes/file.php';
        foreach($_FILES as $f){$files=isset($f['name'])&&is_array($f['name'])?self::normalize_files($f):[$f];foreach($files as $file){if(empty($file['name'])||$file['error']!==UPLOAD_ERR_OK||$file['size']>8*MB_IN_BYTES)continue;$move=wp_handle_upload($file,['test_form'=>false]);if(empty($move['error']))$attachments[]=$move['file'];}}
        $submission['email_delivery']='pending'; $submission['google_delivery']='not_configured';
        $submission_id=wp_insert_post(['post_type'=>'brah_submission','post_status'=>'private','post_title'=>'Form submission – '.current_time('Y-m-d H:i:s'),'post_content'=>wp_json_encode($submission)],true);
        if(is_wp_error($submission_id)){$storage_error=$submission_id->get_error_code();$submission_id=0;self::log_event('storage_failed',['form_type'=>$type,'reason'=>$storage_error]);}
        else self::log_event('submission_stored',['form_type'=>$type,'submission_id'=>$submission_id]);
        $subject='Brooker Ridge – '.($type==='appointment'?'Appointment Request':'New Client Registration').' – '.sanitize_text_field($_POST['pet_name']);
        $headers=['Content-Type: text/plain; charset=UTF-8','Reply-To: '.sanitize_email($_POST['email'])]; $sent=wp_mail(self::EMAIL,$subject,implode("\n\n",$lines),$headers,$attachments);
        foreach($attachments as $p) @unlink($p); $submission['email_delivery']=$sent?'sent':'failed';
        $settings=self::settings(); if(!empty($settings['google_webhook'])&&!empty($settings['google_secret'])){$google=wp_remote_post($settings['google_webhook'],['timeout'=>8,'headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode(['secret'=>$settings['google_secret'],'submission'=>$submission])]);$code=is_wp_error($google)?0:wp_remote_retrieve_response_code($google);$submission['google_delivery']=($code>=200&&$code<300)?'sent':'failed';}
        if($submission_id)wp_update_post(['ID'=>$submission_id,'post_content'=>wp_json_encode($submission)]);
        self::log_event('delivery_complete',['form_type'=>$type,'submission_id'=>$submission_id,'email_delivery'=>$submission['email_delivery'],'google_delivery'=>$submission['google_delivery']]);
        set_transient('brah_'.$ip,1,30);
        wp_safe_redirect(home_url('/form-submission-received/'));exit;
    }
    private static function normalize_files($f){$out=[];foreach($f['name'] as $i=>$n)$out[]=['name'=>$n,'type'=>$f['type'][$i],'tmp_name'=>$f['tmp_name'][$i],'error'=>$f['error'][$i],'size'=>$f['size'][$i]];return $out;}
}
require_once __DIR__.'/includes/class-brah-client-portal.php';
BRAH_Forms::init();
BRAH_Client_Portal::init();
register_activation_hook(__FILE__, ['BRAH_Client_Portal','activate']);
