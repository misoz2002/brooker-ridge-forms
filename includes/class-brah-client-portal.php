<?php
if (!defined('ABSPATH')) exit;

final class BRAH_Client_Portal {
    const OPTION = 'brah_client_portal_settings';
    const ROLE_PENDING = 'brah_client_pending';
    const ROLE_CLIENT = 'brah_client';
    const REQUEST_TYPE = 'brah_client_request';

    public static function init() {
        add_action('init', [__CLASS__, 'register_types_and_roles']);
        add_action('admin_init', [__CLASS__, 'admin_settings']);
        add_action('admin_init', [__CLASS__, 'maybe_create_page'], 20);
        add_action('admin_init', [__CLASS__, 'protect_admin']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_shortcode('brooker_client_portal', [__CLASS__, 'shortcode']);
        add_filter('wp_authenticate_user', [__CLASS__, 'gate_login'], 20, 2);
        add_filter('login_redirect', [__CLASS__, 'login_redirect'], 20, 3);
        add_filter('show_admin_bar', [__CLASS__, 'hide_client_admin_bar']);
        add_filter('the_content', [__CLASS__, 'success_page_invitation']);
        add_filter('wp_robots', [__CLASS__, 'portal_robots']);

        foreach (['register', 'login', 'verify_email', 'resend'] as $action) {
            add_action('admin_post_nopriv_brah_portal_'.$action, [__CLASS__, 'handle_'.$action]);
        }
        add_action('admin_post_brah_portal_verify_email', [__CLASS__, 'handle_verify_email']);
        add_action('admin_post_brah_portal_profile', [__CLASS__, 'handle_profile']);
        add_action('admin_post_brah_portal_pet', [__CLASS__, 'handle_pet']);
        add_action('admin_post_brah_portal_request', [__CLASS__, 'handle_request']);
        add_action('admin_post_brah_portal_account_action', [__CLASS__, 'handle_account_action']);
        add_action('admin_post_brah_portal_request_action', [__CLASS__, 'handle_request_action']);
    }

    public static function activate() { self::register_types_and_roles(); }

    public static function register_types_and_roles() {
        add_role(self::ROLE_PENDING, 'Pending Clinic Client', ['read'=>true]);
        add_role(self::ROLE_CLIENT, 'Clinic Client', ['read'=>true, 'brah_use_client_portal'=>true]);
        $role=get_role(self::ROLE_CLIENT); if($role&&!$role->has_cap('brah_use_client_portal'))$role->add_cap('brah_use_client_portal');
        register_post_type(self::REQUEST_TYPE, ['labels'=>['name'=>'Client Requests'],'public'=>false,'show_ui'=>false,'supports'=>['title','author'],'capability_type'=>'post']);
    }

    private static function defaults() { return ['enabled'=>0,'page_id'=>0]; }
    private static function settings() { return wp_parse_args(get_option(self::OPTION, []), self::defaults()); }
    public static function enabled() { $s=self::settings(); return !empty($s['enabled']); }

    public static function admin_settings() {
        register_setting('brah_client_portal_group', self::OPTION, ['sanitize_callback'=>[__CLASS__, 'sanitize_settings']]);
    }
    public static function sanitize_settings($value) {
        $old=self::settings();
        return ['enabled'=>empty($value['enabled'])?0:1,'page_id'=>absint($value['page_id']??$old['page_id'])];
    }

    public static function maybe_create_page() {
        if(!current_user_can('manage_options')||!self::enabled())return;
        $s=self::settings(); $page_id=absint($s['page_id']);
        if($page_id&&get_post_status($page_id))return;
        $existing=get_page_by_path('client-portal');
        if($existing&&has_shortcode($existing->post_content,'brooker_client_portal'))$page_id=$existing->ID;
        else $page_id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Client Portal','post_name'=>$existing?'brooker-ridge-client-portal':'client-portal','post_content'=>'[brooker_client_portal]'],true);
        if(!is_wp_error($page_id)&&$page_id){$s['page_id']=absint($page_id);update_option(self::OPTION,$s,false);self::log('portal_page_ready',['page_id'=>absint($page_id)]);}
    }

    public static function portal_url() {
        $s=self::settings(); $url=!empty($s['page_id'])?get_permalink(absint($s['page_id'])):'';
        return $url?:home_url('/client-portal/');
    }
    public static function portal_robots($robots) { $s=self::settings();if(!empty($s['page_id'])&&is_page(absint($s['page_id']))){$robots['noindex']=true;$robots['nofollow']=true;}return $robots; }

    public static function admin_menu() {
        add_options_page('Brooker Ridge Client Portal','Brooker Ridge Client Portal','manage_options','brah-client-portal',[__CLASS__,'admin_page']);
    }

    private static function log($event,$context=[]) {
        if(!defined('WP_DEBUG_LOG')||!WP_DEBUG_LOG)return;
        $safe=['event'=>sanitize_key($event),'at'=>gmdate('c')];
        foreach(['user_id','request_id','status','type','reason','page_id'] as $key)if(isset($context[$key]))$safe[$key]=is_numeric($context[$key])?absint($context[$key]):sanitize_key($context[$key]);
        error_log('[Brooker Ridge Client Portal] '.wp_json_encode($safe));
    }
    private static function account_history($user_id,$event,$by=0) {
        $history=get_user_meta($user_id,'brah_account_history',true);$history=is_array($history)?$history:[];$history[]=['event'=>sanitize_key($event),'at'=>current_time('c'),'by'=>absint($by)];update_user_meta($user_id,'brah_account_history',array_slice($history,-100));
    }

    private static function is_client($user=null) {
        $user=$user?:wp_get_current_user(); if(!$user instanceof WP_User||!$user->exists())return false;
        return in_array(self::ROLE_CLIENT,(array)$user->roles,true)&&get_user_meta($user->ID,'brah_client_status',true)==='approved';
    }

    public static function gate_login($user,$password) {
        if(is_wp_error($user)||!$user instanceof WP_User)return $user;
        if(in_array(self::ROLE_PENDING,(array)$user->roles,true)){
            $status=get_user_meta($user->ID,'brah_client_status',true);
            $message=$status==='pending_email'?'Please verify your email address before signing in.':'Your client account is waiting for clinic approval.';
            if(in_array($status,['disabled','declined'],true))$message='This client account is not active. Please contact the clinic.';
            return new WP_Error('brah_client_pending',$message);
        }
        return $user;
    }

    public static function protect_admin() {
        if(!is_user_logged_in()||wp_doing_ajax()||defined('DOING_CRON'))return;
        $user=wp_get_current_user();
        if(self::is_client($user)&&strpos($_SERVER['REQUEST_URI']??'','admin-post.php')===false){wp_safe_redirect(self::portal_url());exit;}
    }
    public static function login_redirect($redirect_to,$requested,$user) { return self::is_client($user)?self::portal_url():$redirect_to; }
    public static function hide_client_admin_bar($show) { return self::is_client()?false:$show; }

    private static function token_fields($context) {
        $token=base64_encode(wp_json_encode(['t'=>time(),'c'=>$context]));
        $sig=hash_hmac('sha256',$token,wp_salt('nonce'));
        return '<input type="hidden" name="portal_token" value="'.esc_attr($token).'"><input type="hidden" name="portal_sig" value="'.esc_attr($sig).'">';
    }
    private static function verify_human($context) {
        $token=sanitize_text_field(wp_unslash($_POST['portal_token']??'')); $sig=sanitize_text_field(wp_unslash($_POST['portal_sig']??''));
        if(!$token||!$sig||!hash_equals(hash_hmac('sha256',$token,wp_salt('nonce')),$sig))return false;
        $decoded=base64_decode($token,true);$data=$decoded===false?null:json_decode($decoded,true);if(!is_array($data))return false;
        $age=time()-absint($data['t']??0);return ($data['c']??'')===$context&&$age>=3&&$age<=7200&&($_POST['human_confirmed']??'')==='1'&&empty($_POST['website']);
    }

    private static function rate_key($action) { return 'brah_portal_'.sanitize_key($action).'_'.hash('sha256',($_SERVER['REMOTE_ADDR']??'').wp_salt()); }
    private static function redirect($status,$extra=[]) { wp_safe_redirect(add_query_arg(['brah_portal'=>$status]+$extra,self::portal_url()));exit; }

    public static function handle_register() {
        if(!self::enabled())self::redirect('disabled');
        if(!isset($_POST['brah_portal_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['brah_portal_nonce'])),'brah_portal_register')||!self::verify_human('register'))self::redirect('security');
        $rate=self::rate_key('register');if(get_transient($rate))self::redirect('rate');set_transient($rate,1,60);
        $email=sanitize_email(wp_unslash($_POST['email']??''));$first=sanitize_text_field(wp_unslash($_POST['first_name']??''));$last=sanitize_text_field(wp_unslash($_POST['last_name']??''));$phone=sanitize_text_field(wp_unslash($_POST['phone']??''));
        $password=wp_unslash((string)($_POST['password']??''));$confirm=wp_unslash((string)($_POST['password_confirm']??''));
        if(!$email||!is_email($email)||!$first||!$last||!$phone||strlen($password)<12||$password!==$confirm||empty($_POST['privacy_consent']))self::redirect('registration_error');
        if(email_exists($email))self::redirect('registration_received');
        $login='brah_'.substr(hash('sha256',strtolower($email)),0,20);
        $user_id=wp_insert_user(['user_login'=>$login,'user_email'=>$email,'user_pass'=>$password,'first_name'=>$first,'last_name'=>$last,'display_name'=>trim($first.' '.$last),'role'=>self::ROLE_PENDING]);
        if(is_wp_error($user_id)){self::log('registration_failed',['reason'=>$user_id->get_error_code()]);self::redirect('registration_error');}
        update_user_meta($user_id,'brah_client_status','pending_email');update_user_meta($user_id,'brah_client_phone',$phone);update_user_meta($user_id,'brah_client_privacy_consent_at',current_time('c'));
        self::account_history($user_id,'registered');
        $sent=self::send_verification($user_id);self::log('account_registered',['user_id'=>$user_id,'status'=>$sent?'pending_email':'email_failed']);
        self::redirect($sent?'registration_received':'verification_email_failed');
    }

    private static function send_verification($user_id) {
        $user=get_userdata($user_id);if(!$user)return false;
        $token=wp_generate_password(40,false,false);update_user_meta($user_id,'brah_verify_hash',hash_hmac('sha256',$token,wp_salt('auth')));update_user_meta($user_id,'brah_verify_expires',time()+2*DAY_IN_SECONDS);
        $url=add_query_arg(['action'=>'brah_portal_verify_email','uid'=>$user_id,'token'=>$token],admin_url('admin-post.php'));
        $message="Hello {$user->first_name},\n\nPlease verify your email address for the Brooker Ridge Animal Hospital Client Portal:\n\n{$url}\n\nAfter verification, the clinic will review and approve your account. This link expires in 48 hours.";
        return wp_mail($user->user_email,'Verify your Brooker Ridge Client Portal email',$message,['Content-Type: text/plain; charset=UTF-8']);
    }

    public static function handle_verify_email() {
        $user_id=absint($_GET['uid']??0);$token=sanitize_text_field(wp_unslash($_GET['token']??''));$user=get_userdata($user_id);$hash=(string)get_user_meta($user_id,'brah_verify_hash',true);$expires=absint(get_user_meta($user_id,'brah_verify_expires',true));
        if(!$user||get_user_meta($user_id,'brah_client_status',true)!=='pending_email'||!$token||!$hash||$expires<time()||!hash_equals($hash,hash_hmac('sha256',$token,wp_salt('auth'))))self::redirect('verification_invalid');
        delete_user_meta($user_id,'brah_verify_hash');delete_user_meta($user_id,'brah_verify_expires');update_user_meta($user_id,'brah_client_status','pending_approval');update_user_meta($user_id,'brah_email_verified_at',current_time('c'));
        self::account_history($user_id,'email_verified',$user_id);
        wp_mail(self::clinic_email(),'Client Portal account awaiting approval',"A client verified their email and is awaiting approval.\n\nReview: ".admin_url('options-general.php?page=brah-client-portal'),['Content-Type: text/plain; charset=UTF-8']);
        self::log('email_verified',['user_id'=>$user_id,'status'=>'pending_approval']);self::redirect('email_verified');
    }

    public static function handle_resend() {
        if(!self::enabled())self::redirect('disabled');if(!isset($_POST['brah_portal_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['brah_portal_nonce'])),'brah_portal_resend'))self::redirect('security');$key=self::rate_key('resend');if(get_transient($key))self::redirect('rate');set_transient($key,1,5*MINUTE_IN_SECONDS);$email=sanitize_email(wp_unslash($_POST['email']??''));$user=$email?get_user_by('email',$email):false;if($user&&get_user_meta($user->ID,'brah_client_status',true)==='pending_email')self::send_verification($user->ID);self::redirect('registration_received');
    }

    public static function handle_login() {
        if(!self::enabled())self::redirect('disabled');
        if(!isset($_POST['brah_portal_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['brah_portal_nonce'])),'brah_portal_login'))self::redirect('security');
        $key=self::rate_key('login');$attempts=absint(get_transient($key));if($attempts>=5)self::redirect('login_rate');
        $creds=['user_login'=>sanitize_email(wp_unslash($_POST['email']??'')),'user_password'=>wp_unslash((string)($_POST['password']??'')),'remember'=>!empty($_POST['remember'])];$user=wp_signon($creds,is_ssl());
        if(is_wp_error($user)){set_transient($key,$attempts+1,10*MINUTE_IN_SECONDS);self::redirect('login_error');}
        delete_transient($key);wp_safe_redirect(self::portal_url());exit;
    }

    private static function clinic_email() { return sanitize_email(defined('BRAH_PORTAL_EMAIL')?BRAH_PORTAL_EMAIL:BRAH_Forms::EMAIL); }

    private static function enqueue_assets() {
        $main=dirname(__DIR__).'/brooker-ridge-forms.php';wp_enqueue_style('brah-client-portal',plugins_url('assets/portal.css',$main),[],BRAH_Forms::VERSION);
    }

    public static function shortcode() {
        if(!self::enabled())return current_user_can('manage_options')?'<div class="brah-portal-notice">The Client Portal is currently disabled. Enable it under Settings &gt; Brooker Ridge Client Portal.</div>':'';
        self::enqueue_assets();
        ob_start();echo '<div class="brah-portal">';self::notice();
        if(!is_user_logged_in())self::guest_view();
        elseif(self::is_client())self::dashboard();
        else echo '<div class="brah-portal-card"><h2>Account not available</h2><p>This signed-in WordPress account is not an approved client account.</p><p><a class="brah-button" href="'.esc_url(wp_logout_url(self::portal_url())).'">Sign out</a></p></div>';
        echo '</div>';return ob_get_clean();
    }

    private static function notice() {
        $status=sanitize_key($_GET['brah_portal']??'');$messages=[
            'verification_email_failed'=>['error','Your request was saved, but the verification email could not be sent. Please contact the clinic.'],
            'email_verified'=>['success','Your email is verified. Your account is now waiting for clinic approval.'],
            'verification_invalid'=>['error','That verification link is invalid or expired. Please contact the clinic for help.'],
            'registration_error'=>['error','Please complete every required field, use matching passwords of at least 12 characters, and try again.'],
            'registration_received'=>['success','If that email can be registered, verification instructions have been sent.'],
            'login_error'=>['error','The email or password was not accepted, or the account is not yet approved.'],
            'login_rate'=>['error','Too many sign-in attempts. Please wait ten minutes and try again.'],
            'rate'=>['error','Please wait a minute before trying again.'],'security'=>['error','The security check expired. Please refresh the page and try again.'],
            'profile_saved'=>['success','Your contact information was updated.'],'pet_saved'=>['success','Your pet profile was saved.'],'pet_removed'=>['success','The pet profile was removed.'],
            'request_received'=>['success','Your request was received. The clinic will review it and contact you if needed.'],'request_error'=>['error','Please complete the required request information and try again.']
        ];
        if(isset($messages[$status]))echo '<div class="brah-portal-notice '.esc_attr($messages[$status][0]).'" role="status">'.esc_html($messages[$status][1]).'</div>';
    }

    private static function guest_view() { ?>
        <header class="brah-portal-hero"><span>Brooker Ridge Animal Hospital</span><h1>Client Portal</h1><p>Save your details and send repeat requests more quickly. Accounts are optional and require clinic approval.</p></header>
        <div class="brah-portal-columns">
          <section class="brah-portal-card"><h2>Sign in</h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="brah_portal_login"><?php wp_nonce_field('brah_portal_login','brah_portal_nonce'); ?>
            <label>Email address<input type="email" name="email" autocomplete="email" required></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label>
            <label class="brah-inline"><input type="checkbox" name="remember" value="1"> Keep me signed in</label><button type="submit">Sign in</button>
            <p><a href="<?php echo esc_url(wp_lostpassword_url(self::portal_url())); ?>">Forgot your password?</a></p>
          </form></section>
          <section class="brah-portal-card"><h2>Create an optional account</h2><p>Use the portal for appointment, refill, and food requests. Public forms remain available.</p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="brah_portal_register"><?php wp_nonce_field('brah_portal_register','brah_portal_nonce');echo self::token_fields('register'); ?>
            <div class="brah-trap" aria-hidden="true"><label>Leave this blank<input name="website" tabindex="-1" autocomplete="off"></label></div>
            <div class="brah-grid"><label>First name<input name="first_name" autocomplete="given-name" required></label><label>Last name<input name="last_name" autocomplete="family-name" required></label></div>
            <label>Email address<input type="email" name="email" autocomplete="email" required></label><label>Cell phone<input type="tel" name="phone" autocomplete="tel" required></label>
            <label>Password <small>(at least 12 characters)</small><input type="password" name="password" autocomplete="new-password" minlength="12" required></label><label>Confirm password<input type="password" name="password_confirm" autocomplete="new-password" minlength="12" required></label>
            <label class="brah-inline"><input type="checkbox" name="privacy_consent" value="1" required> I consent to the clinic using this information to manage my account and requests.</label>
            <label class="brah-human"><input type="checkbox" name="human_confirmed" value="1" required> I’m human</label><button type="submit">Request an account</button>
          </form><details><summary>Didn’t receive the verification email?</summary><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="brah_portal_resend"><?php wp_nonce_field('brah_portal_resend','brah_portal_nonce'); ?><label>Email address<input type="email" name="email" required></label><button type="submit">Resend verification</button></form></details></section>
        </div>
    <?php }

    private static function dashboard() {
        $user=wp_get_current_user();$pets=self::pets($user->ID); ?>
        <header class="brah-portal-hero"><span>Brooker Ridge Animal Hospital</span><h1>Welcome, <?php echo esc_html($user->first_name?:$user->display_name); ?></h1><p>Requests are reviewed by clinic staff and are not confirmed until the clinic contacts you.</p><a class="brah-signout" href="<?php echo esc_url(wp_logout_url(self::portal_url())); ?>">Sign out</a></header>
        <nav class="brah-portal-nav"><a href="#requests">New request</a><a href="#history">Request history</a><a href="#pets">My pets</a><a href="#profile">My details</a></nav>
        <section class="brah-portal-card" id="requests"><h2>Send a request</h2><?php if(!$pets): ?><p>Add a pet profile below before sending a request.</p><?php else:self::request_forms($pets);endif; ?></section>
        <?php self::history($user->ID);self::pets_section($pets);self::profile_section($user); ?>
    <?php }

    private static function pet_options($pets) { foreach($pets as $pet)echo '<option value="'.esc_attr($pet['id']).'">'.esc_html($pet['name'].' — '.$pet['species']).'</option>'; }
    private static function request_forms($pets) { ?>
      <div class="brah-request-grid">
        <form class="brah-request-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><h3>Appointment</h3><input type="hidden" name="action" value="brah_portal_request"><input type="hidden" name="request_type" value="appointment"><?php wp_nonce_field('brah_portal_request','brah_portal_nonce'); ?><label>Pet<select name="pet_id" required><option value="">Choose a pet</option><?php self::pet_options($pets); ?></select></label><label>Main reason<input name="reason" required></label><label>Preferred date<input type="date" name="preferred_date"></label><label>Preferred time<select name="preferred_time"><option value="">No preference</option><option>Morning</option><option>Afternoon</option><option>Evening</option></select></label><label>Additional details<textarea name="notes" rows="3"></textarea></label><label class="brah-inline"><input type="checkbox" name="acknowledgement" value="1" required> I understand this is a request, not a confirmed appointment.</label><button type="submit">Request appointment</button></form>
        <form class="brah-request-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><h3>Medication refill</h3><input type="hidden" name="action" value="brah_portal_request"><input type="hidden" name="request_type" value="refill"><?php wp_nonce_field('brah_portal_request','brah_portal_nonce'); ?><label>Pet<select name="pet_id" required><option value="">Choose a pet</option><?php self::pet_options($pets); ?></select></label><label>Medication name<input name="item_name" required></label><label>Current directions or strength<input name="item_details" required></label><label>Quantity requested<input name="quantity"></label><label>Additional notes<textarea name="notes" rows="3"></textarea></label><label class="brah-inline"><input type="checkbox" name="acknowledgement" value="1" required> I understand refills require veterinary approval.</label><button type="submit">Request refill</button></form>
        <form class="brah-request-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><h3>Food order</h3><input type="hidden" name="action" value="brah_portal_request"><input type="hidden" name="request_type" value="food"><?php wp_nonce_field('brah_portal_request','brah_portal_nonce'); ?><label>Pet<select name="pet_id" required><option value="">Choose a pet</option><?php self::pet_options($pets); ?></select></label><label>Food/product name<input name="item_name" required></label><label>Bag/case size<input name="item_details"></label><label>Quantity<input name="quantity" required></label><label>Additional notes<textarea name="notes" rows="3"></textarea></label><label class="brah-inline"><input type="checkbox" name="acknowledgement" value="1" required> I understand the clinic will confirm availability and pickup.</label><button type="submit">Request food</button></form>
      </div>
    <?php }

    private static function history($user_id) {
        $posts=get_posts(['post_type'=>self::REQUEST_TYPE,'post_status'=>'private','author'=>$user_id,'numberposts'=>25,'orderby'=>'date','order'=>'DESC']); ?>
        <section class="brah-portal-card" id="history"><h2>Request history</h2><?php if(!$posts): ?><p>No requests yet.</p><?php else: ?><div class="brah-history"><?php foreach($posts as $post):$data=json_decode($post->post_content,true)?:[];$status=get_post_meta($post->ID,'_brah_request_status',true)?:'received';$note=get_post_meta($post->ID,'_brah_client_message',true); ?><article><div><strong><?php echo esc_html(ucfirst($data['type']??'request').' — '.($data['pet_name']??'')); ?></strong><small><?php echo esc_html(get_the_date('F j, Y g:i a',$post)); ?></small></div><span class="brah-status status-<?php echo esc_attr($status); ?>"><?php echo esc_html(self::status_label($status)); ?></span><?php if($note): ?><p><?php echo esc_html($note); ?></p><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?></section>
    <?php }

    private static function pets($user_id) { $pets=get_user_meta($user_id,'brah_client_pets',true);return is_array($pets)?array_values($pets):[]; }
    private static function pets_section($pets) { ?>
      <section class="brah-portal-card" id="pets"><h2>My pets</h2><div class="brah-pet-list"><?php foreach($pets as $pet): ?><article><h3><?php echo esc_html($pet['name']); ?></h3><p><?php echo esc_html(implode(' · ',array_filter([$pet['species'],$pet['breed'],$pet['age']]))); ?></p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="brah_portal_pet"><input type="hidden" name="mode" value="delete"><input type="hidden" name="pet_id" value="<?php echo esc_attr($pet['id']); ?>"><?php wp_nonce_field('brah_portal_pet','brah_portal_nonce'); ?><button class="brah-link-button" type="submit">Remove</button></form></article><?php endforeach; ?></div>
      <details><summary>Add a pet</summary><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="brah_portal_pet"><input type="hidden" name="mode" value="add"><?php wp_nonce_field('brah_portal_pet','brah_portal_nonce'); ?><div class="brah-grid"><label>Pet name<input name="pet_name" required></label><label>Species<select name="species" required><option value="">Choose one</option><option>Dog</option><option>Cat</option><option>Other</option></select></label><label>Breed<input name="breed"></label><label>Age or birth date<input name="age"></label><label>Colour<input name="colour"></label></div><button type="submit">Save pet</button></form></details></section>
    <?php }

    private static function profile_section($user) { ?>
      <section class="brah-portal-card" id="profile"><h2>My details</h2><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="brah_portal_profile"><?php wp_nonce_field('brah_portal_profile','brah_portal_nonce'); ?><div class="brah-grid"><label>First name<input name="first_name" value="<?php echo esc_attr($user->first_name); ?>" required></label><label>Last name<input name="last_name" value="<?php echo esc_attr($user->last_name); ?>" required></label><label>Email address<input value="<?php echo esc_attr($user->user_email); ?>" disabled></label><label>Cell phone<input name="phone" value="<?php echo esc_attr(get_user_meta($user->ID,'brah_client_phone',true)); ?>" required></label><label>Street address<input name="street" value="<?php echo esc_attr(get_user_meta($user->ID,'brah_client_street',true)); ?>"></label><label>Unit/suite<input name="unit" value="<?php echo esc_attr(get_user_meta($user->ID,'brah_client_unit',true)); ?>"></label><label>City<input name="city" value="<?php echo esc_attr(get_user_meta($user->ID,'brah_client_city',true)); ?>"></label><label>Province<input name="province" maxlength="2" value="<?php echo esc_attr(get_user_meta($user->ID,'brah_client_province',true)); ?>"></label><label>Postal code<input name="postal_code" value="<?php echo esc_attr(get_user_meta($user->ID,'brah_client_postal_code',true)); ?>"></label></div><button type="submit">Save my details</button></form></section>
    <?php }

    private static function require_client() { if(!self::enabled()||!self::is_client())wp_die('Not authorized.','Forbidden',['response'=>403]);return get_current_user_id(); }
    public static function handle_profile() {
        $user_id=self::require_client();check_admin_referer('brah_portal_profile','brah_portal_nonce');$first=sanitize_text_field(wp_unslash($_POST['first_name']??''));$last=sanitize_text_field(wp_unslash($_POST['last_name']??''));$phone=sanitize_text_field(wp_unslash($_POST['phone']??''));if(!$first||!$last||!$phone)self::redirect('request_error');
        wp_update_user(['ID'=>$user_id,'first_name'=>$first,'last_name'=>$last,'display_name'=>trim($first.' '.$last)]);foreach(['phone','street','unit','city','province','postal_code'] as $key)update_user_meta($user_id,'brah_client_'.$key,sanitize_text_field(wp_unslash($_POST[$key]??'')));self::log('profile_updated',['user_id'=>$user_id]);self::redirect('profile_saved');
    }
    public static function handle_pet() {
        $user_id=self::require_client();check_admin_referer('brah_portal_pet','brah_portal_nonce');$pets=self::pets($user_id);$mode=sanitize_key($_POST['mode']??'add');
        if($mode==='delete'){$id=sanitize_text_field(wp_unslash($_POST['pet_id']??''));$pets=array_values(array_filter($pets,function($pet)use($id){return ($pet['id']??'')!==$id;}));update_user_meta($user_id,'brah_client_pets',$pets);self::log('pet_removed',['user_id'=>$user_id]);self::redirect('pet_removed');}
        if(count($pets)>=20)self::redirect('request_error');$name=sanitize_text_field(wp_unslash($_POST['pet_name']??''));$species=sanitize_text_field(wp_unslash($_POST['species']??''));if(!$name||!in_array($species,['Dog','Cat','Other'],true))self::redirect('request_error');
        $pets[]=['id'=>wp_generate_uuid4(),'name'=>$name,'species'=>$species,'breed'=>sanitize_text_field(wp_unslash($_POST['breed']??'')),'age'=>sanitize_text_field(wp_unslash($_POST['age']??'')),'colour'=>sanitize_text_field(wp_unslash($_POST['colour']??''))];update_user_meta($user_id,'brah_client_pets',$pets);self::log('pet_saved',['user_id'=>$user_id]);self::redirect('pet_saved');
    }

    public static function handle_request() {
        $user_id=self::require_client();check_admin_referer('brah_portal_request','brah_portal_nonce');$type=sanitize_key($_POST['request_type']??'');if(!in_array($type,['appointment','refill','food'],true)||empty($_POST['acknowledgement']))self::redirect('request_error');
        $pet_id=sanitize_text_field(wp_unslash($_POST['pet_id']??''));$pet=null;foreach(self::pets($user_id) as $candidate)if(($candidate['id']??'')===$pet_id)$pet=$candidate;if(!$pet)self::redirect('request_error');
        $data=['type'=>$type,'pet_id'=>$pet_id,'pet_name'=>$pet['name'],'submitted_at'=>current_time('c'),'reason'=>sanitize_text_field(wp_unslash($_POST['reason']??'')),'preferred_date'=>sanitize_text_field(wp_unslash($_POST['preferred_date']??'')),'preferred_time'=>sanitize_text_field(wp_unslash($_POST['preferred_time']??'')),'item_name'=>sanitize_text_field(wp_unslash($_POST['item_name']??'')),'item_details'=>sanitize_text_field(wp_unslash($_POST['item_details']??'')),'quantity'=>sanitize_text_field(wp_unslash($_POST['quantity']??'')),'notes'=>sanitize_textarea_field(wp_unslash($_POST['notes']??''))];
        if($type==='appointment'&&!$data['reason'])self::redirect('request_error');if(in_array($type,['refill','food'],true)&&!$data['item_name'])self::redirect('request_error');if($type==='refill'&&!$data['item_details'])self::redirect('request_error');if($type==='food'&&!$data['quantity'])self::redirect('request_error');
        $post_id=wp_insert_post(['post_type'=>self::REQUEST_TYPE,'post_status'=>'private','post_author'=>$user_id,'post_title'=>ucfirst($type).' request — '.current_time('Y-m-d H:i:s'),'post_content'=>wp_slash(wp_json_encode($data))],true);if(is_wp_error($post_id)){self::log('request_storage_failed',['user_id'=>$user_id,'type'=>$type,'reason'=>$post_id->get_error_code()]);self::redirect('request_error');}
        update_post_meta($post_id,'_brah_request_status','received');update_post_meta($post_id,'_brah_status_history',[['status'=>'received','at'=>current_time('c'),'by'=>$user_id]]);$user=get_userdata($user_id);
        $summary="Client: {$user->display_name}\nEmail: {$user->user_email}\nPet: {$pet['name']}\nType: {$type}\n\n";foreach($data as $key=>$value)if($value&&!in_array($key,['pet_id','submitted_at','type'],true))$summary.=ucwords(str_replace('_',' ',$key)).": {$value}\n";
        $sent=wp_mail(self::clinic_email(),'Client Portal — '.ucfirst($type).' request',$summary,['Content-Type: text/plain; charset=UTF-8','Reply-To: '.$user->user_email]);update_post_meta($post_id,'_brah_email_delivery',$sent?'sent':'failed');wp_mail($user->user_email,'We received your Brooker Ridge request',"Hello {$user->first_name},\n\nWe received your {$type} request for {$pet['name']}. The clinic will review it and contact you if needed. Requests are not confirmed until the clinic responds.",['Content-Type: text/plain; charset=UTF-8']);self::log('request_stored',['user_id'=>$user_id,'request_id'=>$post_id,'type'=>$type,'status'=>'received']);self::redirect('request_received');
    }

    private static function status_label($status) { $labels=['received'=>'Received','reviewing'=>'Under review','needs_info'=>'More information needed','approved'=>'Approved','ready'=>'Ready for pickup','completed'=>'Completed','declined'=>'Unable to fulfill'];return $labels[$status]??'Received'; }

    public static function admin_page() {
        if(!current_user_can('manage_options'))return;$s=self::settings();$users=get_users(['role__in'=>[self::ROLE_PENDING,self::ROLE_CLIENT],'orderby'=>'registered','order'=>'DESC']);$requests=get_posts(['post_type'=>self::REQUEST_TYPE,'post_status'=>'private','numberposts'=>100,'orderby'=>'date','order'=>'DESC']);
        foreach($users as $user)self::account_history($user->ID,'admin_viewed',get_current_user_id());foreach($requests as $request){$log=get_post_meta($request->ID,'_brah_admin_access_log',true);$log=is_array($log)?$log:[];$log[]=['user_id'=>get_current_user_id(),'at'=>current_time('c')];update_post_meta($request->ID,'_brah_admin_access_log',array_slice($log,-100));}
        ?>
        <div class="wrap"><h1>Brooker Ridge Client Portal</h1><?php if(isset($_GET['updated'])):?><div class="notice notice-success"><p>Client Portal updated.</p></div><?php endif; ?>
        <form method="post" action="options.php"><?php settings_fields('brah_client_portal_group'); ?><input type="hidden" name="<?php echo esc_attr(self::OPTION); ?>[page_id]" value="<?php echo absint($s['page_id']); ?>"><table class="form-table"><tr><th>Enable client portal</th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[enabled]" value="1" <?php checked(!empty($s['enabled'])); ?>> Allow clients to register and use the portal</label><p class="description">Accounts remain optional and require email verification plus clinic approval.</p></td></tr><tr><th>Portal page</th><td><?php if(!empty($s['page_id'])&&get_post_status($s['page_id'])):?><a href="<?php echo esc_url(get_permalink($s['page_id'])); ?>" target="_blank" rel="noopener">View Client Portal</a><?php else: ?>The page will be created after the portal is enabled.<?php endif; ?></td></tr></table><?php submit_button('Save Client Portal Settings'); ?></form>
        <hr><h2>Client accounts</h2><table class="widefat striped"><thead><tr><th>Client</th><th>Email / phone</th><th>Registered</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php if(!$users):?><tr><td colspan="5">No client accounts yet.</td></tr><?php endif;foreach($users as $user):$status=get_user_meta($user->ID,'brah_client_status',true)?:'pending_email';?><tr><td><?php echo esc_html($user->display_name);?></td><td><?php echo esc_html($user->user_email);?><br><?php echo esc_html(get_user_meta($user->ID,'brah_client_phone',true));?></td><td><?php echo esc_html(get_date_from_gmt($user->user_registered,'Y-m-d g:i a'));?></td><td><?php echo esc_html(ucwords(str_replace('_',' ',$status)));?></td><td><?php self::account_actions($user,$status);?></td></tr><?php endforeach;?></tbody></table>
        <hr><h2>Client requests</h2><table class="widefat striped"><thead><tr><th>Date</th><th>Client / pet</th><th>Request</th><th>Status and client message</th></tr></thead><tbody><?php if(!$requests):?><tr><td colspan="4">No client requests yet.</td></tr><?php endif;foreach($requests as $request):$data=json_decode($request->post_content,true)?:[];$user=get_userdata($request->post_author);$status=get_post_meta($request->ID,'_brah_request_status',true)?:'received';?><tr><td><?php echo esc_html(get_the_date('Y-m-d g:i a',$request));?></td><td><?php echo esc_html($user?$user->display_name:'Deleted client');?><br><?php echo esc_html($data['pet_name']??'');?></td><td><strong><?php echo esc_html(ucfirst($data['type']??'request'));?></strong><details><summary>View submitted details</summary><dl><?php foreach($data as $key=>$value)if($value&&!in_array($key,['pet_id'],true)):?><dt><strong><?php echo esc_html(ucwords(str_replace('_',' ',$key)));?></strong></dt><dd><?php echo esc_html($value);?></dd><?php endif;?></dl></details></td><td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="brah_portal_request_action"><input type="hidden" name="request_id" value="<?php echo absint($request->ID);?>"><?php wp_nonce_field('brah_portal_request_'.$request->ID,'brah_portal_nonce');?><select name="status"><?php foreach(['received','reviewing','needs_info','approved','ready','completed','declined'] as $value):?><option value="<?php echo esc_attr($value);?>" <?php selected($status,$value);?>><?php echo esc_html(self::status_label($value));?></option><?php endforeach;?></select><br><textarea name="client_message" rows="2" placeholder="Optional message visible to client"><?php echo esc_textarea(get_post_meta($request->ID,'_brah_client_message',true));?></textarea><br><button class="button button-primary" type="submit">Update request</button></form></td></tr><?php endforeach;?></tbody></table><p><em>Portal data is private. Administrative views and status changes are logged.</em></p></div>
    <?php }

    private static function account_actions($user,$status) {
        $actions=[];if($status==='pending_email')$actions=['verify_approve'=>'Verify & approve','resend'=>'Resend verification'];elseif($status==='pending_approval')$actions=['approve'=>'Approve','decline'=>'Decline'];elseif($status==='approved')$actions=['disable'=>'Disable'];else $actions=['approve'=>'Approve'];
        foreach($actions as $action=>$label){?><form style="display:inline-block;margin:0 5px 5px 0" method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="brah_portal_account_action"><input type="hidden" name="client_id" value="<?php echo absint($user->ID);?>"><input type="hidden" name="account_action" value="<?php echo esc_attr($action);?>"><?php wp_nonce_field('brah_portal_account_'.$user->ID,'brah_portal_nonce');?><button class="button <?php echo in_array($action,['approve','verify_approve'],true)?'button-primary':'';?>" type="submit"><?php echo esc_html($label);?></button></form><?php }
    }

    public static function handle_account_action() {
        if(!current_user_can('manage_options'))wp_die('Not authorized.','Forbidden',['response'=>403]);$user_id=absint($_POST['client_id']??0);check_admin_referer('brah_portal_account_'.$user_id,'brah_portal_nonce');$user=get_userdata($user_id);if(!$user||(!in_array(self::ROLE_PENDING,(array)$user->roles,true)&&!in_array(self::ROLE_CLIENT,(array)$user->roles,true)))wp_die('Invalid client account.','Bad request',['response'=>400]);$action=sanitize_key($_POST['account_action']??'');
        if($action==='resend')self::send_verification($user_id);
        elseif(in_array($action,['approve','verify_approve'],true)){$user->set_role(self::ROLE_CLIENT);update_user_meta($user_id,'brah_client_status','approved');if($action==='verify_approve'){delete_user_meta($user_id,'brah_verify_hash');delete_user_meta($user_id,'brah_verify_expires');update_user_meta($user_id,'brah_email_verified_at',current_time('c'));}wp_mail($user->user_email,'Your Brooker Ridge Client Portal account is approved',"Hello {$user->first_name},\n\nYour account is approved. You can now sign in at:\n".self::portal_url(),['Content-Type: text/plain; charset=UTF-8']);}
        elseif(in_array($action,['disable','decline'],true)){$user->set_role(self::ROLE_PENDING);update_user_meta($user_id,'brah_client_status',$action==='disable'?'disabled':'declined');}
        self::account_history($user_id,$action,get_current_user_id());self::log('account_action',['user_id'=>$user_id,'status'=>$action]);wp_safe_redirect(admin_url('options-general.php?page=brah-client-portal&updated=1'));exit;
    }

    public static function handle_request_action() {
        if(!current_user_can('manage_options'))wp_die('Not authorized.','Forbidden',['response'=>403]);$request_id=absint($_POST['request_id']??0);check_admin_referer('brah_portal_request_'.$request_id,'brah_portal_nonce');$post=get_post($request_id);if(!$post||$post->post_type!==self::REQUEST_TYPE)wp_die('Invalid request.','Bad request',['response'=>400]);$status=sanitize_key($_POST['status']??'');if(!in_array($status,['received','reviewing','needs_info','approved','ready','completed','declined'],true))wp_die('Invalid status.','Bad request',['response'=>400]);$message=sanitize_textarea_field(wp_unslash($_POST['client_message']??''));update_post_meta($request_id,'_brah_request_status',$status);update_post_meta($request_id,'_brah_client_message',$message);$history=get_post_meta($request_id,'_brah_status_history',true);$history=is_array($history)?$history:[];$history[]=['status'=>$status,'at'=>current_time('c'),'by'=>get_current_user_id()];update_post_meta($request_id,'_brah_status_history',$history);$user=get_userdata($post->post_author);if($user)wp_mail($user->user_email,'Update to your Brooker Ridge request',"Hello {$user->first_name},\n\nYour request status is now: ".self::status_label($status).($message?"\n\nClinic message: {$message}":'')."\n\nView your portal: ".self::portal_url(),['Content-Type: text/plain; charset=UTF-8']);self::log('request_status_changed',['user_id'=>$post->post_author,'request_id'=>$request_id,'status'=>$status]);wp_safe_redirect(admin_url('options-general.php?page=brah-client-portal&updated=1'));exit;
    }

    public static function success_page_invitation($content) {
        if(!self::enabled()||is_admin()||!in_the_loop()||!is_main_query()||!is_page('form-submission-received')||is_user_logged_in())return $content;
        self::enqueue_assets();return $content.'<div class="brah-portal-invite"><h2>Want faster requests next time?</h2><p>Create an optional, clinic-approved account to save your details and request appointments, refills, and food.</p><p><a class="brah-button" href="'.esc_url(self::portal_url()).'">Create a Client Portal account</a></p></div>';
    }
}
