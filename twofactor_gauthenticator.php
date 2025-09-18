<?php

/**
 * Two-factor Google Authenticator for RoundCube
 *
 * This RoundCube plugin adds the 2-step verification(OTP) to the login proccess
 *
 * @version 2.0.0
 * @author Alexandre Espinosa <aemenor@gmail.com>
 *
 * Some ideas and code: Ricardo Signes <rjbs@cpan.org>, Ricardo Iván Vieitez Parra (https://github.com/corrideat), Justin Buchanan (https://github.com/jusbuc2k)
 * 	, https://github.com/pokrface, Peter Tobias, Víctor R. Rodríguez Domínguez (https://github.com/vrdominguez), etc.
 * Date: 2013-11-30
 */
require_once 'PHPGangsta/GoogleAuthenticator.php';

require_once 'CIDR.php';

class twofactor_gauthenticator extends rcube_plugin
{
    private $_number_recovery_codes = 4;

    // relative to $config['log_dir']
    private $_logs_file = 'log_errors_2FA.txt';

    public function init()
    {
        $rcmail = rcmail::get_instance();

        // Completely block AJAX requests for unauthenticated users (by Stephen K. Gielda <security@codamail.com>)
        if (!$rcmail->user->ID && !isset($_SESSION['twofactor_gauthenticator_login']) && isset($_REQUEST['_remote'])) {

            // Direct JSON response to prevent leakage
            header('Content-Type: application/json');
            echo json_encode(array(
                'error' => 'Session expired or invalid',
                'redirect' => '?_task=login&_err=session'
            ));
            exit;
        }

        // Block data access via AJAX for partially authenticated users who have 2FA enabled (by Stephen K. Gielda <security@codamail.com>)
	if (isset($_SESSION['twofactor_gauthenticator_login']) && 
	    (!isset($_SESSION['twofactor_gauthenticator_2FA_login']) || 
	     $_SESSION['twofactor_gauthenticator_2FA_login'] < $_SESSION['twofactor_gauthenticator_login']) && 
	    isset($_REQUEST['_remote']) &&
	    $rcmail->action !== 'plugin.twofactor_gauthenticator-checkcode' &&
	    $rcmail->task !== 'login') {
	    
	    // Get user's 2FA config
	    $user_prefs = $rcmail->user->get_prefs();
	    $tfa_config = isset($user_prefs['twofactor_gauthenticator']) ? $user_prefs['twofactor_gauthenticator'] : null;
	    
	    // Only block if 2FA is enabled for this user
	    if ($tfa_config && isset($tfa_config['activate']) && $tfa_config['activate']) {
		// Direct JSON response to prevent leakage
		header('Content-Type: application/json');
		echo json_encode(array(
		    'error' => '2FA authentication required',
		    'redirect' => '?_task=login&_err=session'
		));
		exit;
	    }
	}

        // hooks
        $this->add_hook('login_after', array($this, 'login_after'));
        $this->add_hook('send_page', array($this, 'check_2FAlogin'));
        $this->add_hook('render_page', array($this, 'popup_msg_enrollment'));

        $this->load_config();

        $allowedPlugin = $this->__pluginAllowedByConfig();

        // skipping all logic and plugin not appears
        if (!$allowedPlugin) {
            return false;
        }

        $this->add_texts('localization/', true);

        // check code with ajax
        $this->register_action('plugin.twofactor_gauthenticator-checkcode', array($this, 'checkCode'));

        // config
        $this->register_action('twofactor_gauthenticator', array($this, 'twofactor_gauthenticator_init'));
        $this->register_action('plugin.twofactor_gauthenticator-save', array($this, 'twofactor_gauthenticator_save'));
        $this->include_script('twofactor_gauthenticator.js');
        $this->include_script('qrcode.min.js');

        // settings we will export to the form javascript
        //$this_output = $this->api->output;
        //if ($this_output) {
        //	$this->api->output->set_env('allow_save_device_30days',$rcmail->config->get('allow_save_device_30days',true));
        //	$this->api->output->set_env('twofactor_formfield_as_password',$rcmail->config->get('twofactor_formfield_as_password',false));
        //}
    }

    // check if user are valid from config.inc.php or true (by default) if config.inc.php not exists
    public function __pluginAllowedByConfig()
    {
        $rcmail = rcmail::get_instance();

        $this->load_config();

        // users allowed to use plugin (not showed for others!).
        //	-- From config.inc.php file.
        //  -- You can use regexp: admin.*@domain.com
        $users = $rcmail->config->get('users_allowed_2FA');
        if (is_array($users)) {		// exists "users" from config.inc.php
            foreach ($users as $u) {
                if (isset($rcmail->user->data['username'])) {
                    preg_match("/$u/", $rcmail->user->data['username'], $matches);

                    if (isset($matches[0])) {
                        return true;
                    }
                }
            }

            // not allowed for all, except explicit
            return false;
        }

        // by default, all users have plugin activated
        return true;
    }

    // Use the form login, but removing inputs with jquery and action (see twofactor_gauthenticator_form.js)
    public function login_after($args)
    {
        $_SESSION['twofactor_gauthenticator_login'] = time();

        $rcmail = rcmail::get_instance();


        $config_2FA = self::__get2FAconfig();
        if (!($config_2FA['activate'] ?? false)) {
            if ($rcmail->config->get('force_enrollment_users')) {
                $this->__goingRoundcubeTask('settings', 'plugin.twofactor_gauthenticator');
            }
            return;
        }

        if ($this->__cookie($set = false) || !$this->__pluginAllowedByConfig()) {
            $_SESSION['twofactor_gauthenticator_login'] -= 1; // so that we may use ge to check for valid session
            $this->__goingRoundcubeTask('mail');
            return;
        }

        $rcmail->output->set_pagetitle($this->gettext('twofactor_gauthenticator'));

        $rcmail->output->set_env('allow_save_device_30days', $rcmail->config->get('allow_save_device_30days', true));
        $rcmail->output->set_env('twofactor_formfield_as_password', $rcmail->config->get('twofactor_formfield_as_password', false));

        $this->add_texts('localization', true);
        $this->include_script('twofactor_gauthenticator_form.js');

        $rcmail->output->send('login');
    }

    // capture webpage if someone try to use ?_task=mail|addressbook|settings|... and check auth code
    public function check_2FAlogin($p)
    {
        $rcmail = rcmail::get_instance();
        $config_2FA = self::__get2FAconfig();

        if ($config_2FA['activate'] ?? false) {
            // with IP allowed, we don't need to check anything
            if ($rcmail->config->get('whitelist')) {
                foreach ($rcmail->config->get('whitelist') as $ip_to_check) {
                    if (isset($_SERVER['HTTP_CLIENT_IP']) && array_key_exists('HTTP_CLIENT_IP', $_SERVER)) {
                        $realip = $_SERVER['HTTP_CLIENT_IP'];
                    } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) {
                        $realips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                        $realips = array_map('trim', $realips);
                        $realip = $realips[0];
                    } else {
                        $realip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                    }
                    if (CIDR::match($realip, $ip_to_check)) {
                        if (isset($_SESSION['twofactor_gauthenticator_login'])) {
                            if ($rcmail->task === 'login') {
                                $this->__goingRoundcubeTask('mail');
                            }
                            return $p;
                        }
                    }
                }
            }


            $code = rcube_utils::get_input_value('_code_2FA', rcube_utils::INPUT_POST);
            $remember = rcube_utils::get_input_value('_remember_2FA', rcube_utils::INPUT_POST);

            if ($code) {
                if (self::__checkCode($code) || self::__isRecoveryCode($code)) {
                    if (self::__isRecoveryCode($code)) {
                        self::__consumeRecoveryCode($code);
                    }

                    if (rcube_utils::get_input_value('_remember_2FA', rcube_utils::INPUT_POST) === 'yes') {
                        $this->__cookie($set = true);
                    }

                    $this->__goingRoundcubeTask('mail');
                } else {
                    if ($rcmail->config->get('enable_fail_logs')) {
                        $this->__logError();
                    }
                    $this->__exitSession();
                }
            }
            // we're into some task but marked with login...
            elseif ($rcmail->task !== 'login' && ! $_SESSION['twofactor_gauthenticator_2FA_login'] >= $_SESSION['twofactor_gauthenticator_login']) {
                $this->__exitSession();
            }

        } elseif ($rcmail->config->get('force_enrollment_users') && ($rcmail->task !== 'settings' || $rcmail->action !== 'plugin.twofactor_gauthenticator')) {
            if ($rcmail->task !== 'login') {	// resolve some redirection loop with logout
                $this->__goingRoundcubeTask('settings', 'plugin.twofactor_gauthenticator');
            }
        }

        return $p;
    }

    // ripped from new_user_dialog plugin
    public function popup_msg_enrollment()
    {
        $rcmail = rcmail::get_instance();
        $config_2FA = self::__get2FAconfig();

        if (!($config_2FA['activate'] ?? false)
            && $rcmail->config->get('force_enrollment_users') && $rcmail->task == 'settings' && $rcmail->action == 'plugin.twofactor_gauthenticator') {
            // add overlay input box to html page
            $rcmail->output->add_footer(html::tag(
                'form',
                array(
                    'id' => 'enrollment_dialog',
                    'method' => 'post'),
                html::tag('h3', null, $this->gettext('enrollment_dialog_title')) .
                    $this->gettext('enrollment_dialog_msg')
            ));

            $rcmail->output->add_script(
                "$('#enrollment_dialog').show().dialog({ modal:true, resizable:false, closeOnEscape: true, width:420 });",
                'docready'
            );
        }
    }

    // show config
    public function twofactor_gauthenticator_init()
    {
        $rcmail = rcmail::get_instance();

        $this->add_texts('localization/', true);
        $this->register_handler('plugin.body', array($this, 'twofactor_gauthenticator_form'));

        $rcmail->output->set_pagetitle($this->gettext('twofactor_gauthenticator'));
        $rcmail->output->send('plugin');
    }

    // save config
    public function twofactor_gauthenticator_save()
    {
        $rcmail = rcmail::get_instance();

        // Verify user is authenticated before allowing changes (by Stephen K. Gielda <security@codamail.com>)
        if (!$rcmail->user->ID) {
            header('Location: ?_task=login');
            exit;
        }

        // 2022-04-03: Corrected security incidente reported by kototilt@haiiro.dev
        //					"2FA in twofactor_gauthenticator can be bypassed allowing an attacker to disable 2FA or change the TOTP secret."
        //
        // Solution: if user don't have session created by any rendered page, we kick out
        $config_2FA = self::__get2FAconfig();
        if (!$_SESSION['twofactor_gauthenticator_2FA_login'] && $config_2FA['activate']) {
            $this->__exitSession();
        }

        $this->add_texts('localization/', true);
        $this->register_handler('plugin.body', array($this, 'twofactor_gauthenticator_form'));
        $rcmail->output->set_pagetitle($this->gettext('twofactor_gauthenticator'));

        // POST variables
        $activate = rcube_utils::get_input_value('2FA_activate', rcube_utils::INPUT_POST);
        $secret = rcube_utils::get_input_value('2FA_secret', rcube_utils::INPUT_POST);
        $recovery_codes = rcube_utils::get_input_value('2FA_recovery_codes', rcube_utils::INPUT_POST);

        // remove recovery codes without value
        $recovery_codes = array_values(array_diff($recovery_codes, array('')));

        $data = self::__get2FAconfig();
        $data['secret'] = $secret;
        $data['activate'] = $activate ? true : false;
        $data['recovery_codes'] = $recovery_codes;
        self::__set2FAconfig($data);

        // if we can't save time into SESSION, the plugin logouts
        $_SESSION['twofactor_gauthenticator_2FA_login'] = time();

        $rcmail->output->show_message($this->gettext('successfully_saved'), 'confirmation');

        $rcmail->overwrite_action('plugin.twofactor_gauthenticator');
        $rcmail->output->send('plugin');
    }

    // form config


public function twofactor_gauthenticator_form()
{
    $rcmail = rcmail::get_instance();

    $this->add_texts('localization/', true);
    $rcmail->output->set_env('product_name', $rcmail->config->get('product_name'));

    $data = self::__get2FAconfig();

    // Fields will be positioned inside of a table
    $table = new html_table(array('cols' => 2));

    // Activate/deactivate
    $field_id = '2FA_activate';
    $checkbox_activate = new html_checkbox(array('name' => $field_id, 'id' => $field_id, 'type' => 'checkbox'));
    $table->add('title', html::label($field_id, rcube::Q($this->gettext('activate'))));
    $checked = (isset($data['activate']) && $data['activate']) ? null : 1; // :-?
    $table->add(null, $checkbox_activate->show($checked));

    // secret
    $field_id = '2FA_secret';
    $input_descsecret = new html_inputfield(array('name' => $field_id, 'id' => $field_id, 'size' => 60, 'type' => 'password', 'value' => $data['secret'] ?? '', 'autocomplete' => 'new-password'));
    $table->add('title', html::label($field_id, rcube::Q($this->gettext('secret'))));
    $html_secret = $input_descsecret->show();
    if ($data['secret'] ?? '') {
        $html_secret .= ' &nbsp; <input type="button" class="button mainaction" id="2FA_change_secret" value="'.$this->gettext('show_secret').'">';
    } else {
        $html_secret .= ' &nbsp; <input type="button" class="button mainaction" id="2FA_create_secret" disabled="disabled" value="'.$this->gettext('create_secret').'">';
    }
    $table->add(null, $html_secret);

    // recovery codes
    $table->add('title', $this->gettext('recovery_codes'));

    $html_recovery_codes = '';
    $i = 0;
    for ($i = 0; $i < $this->_number_recovery_codes; $i++) {
        $value = isset($data['recovery_codes'][$i]) ? $data['recovery_codes'][$i] : '';
        $html_recovery_codes .= ' <input type="password" name="2FA_recovery_codes[]" value="'.$value.'" maxlength="10" style="margin: 2px;"> &nbsp; ';
    }
    if ($data['secret'] ?? '') {
        $html_recovery_codes .= '<input type="button" class="button mainaction" id="2FA_show_recovery_codes" value="'.$this->gettext('show_recovery_codes').'">';
    } else {
        $html_recovery_codes .= '<input type="button" class="button mainaction" id="2FA_show_recovery_codes" disabled="disabled" value="'.$this->gettext('show_recovery_codes').'">';
    }
    $table->add(null, $html_recovery_codes);

    // qr-code
    if ($data['secret'] ?? '') {
        $table->add('title', $this->gettext('qr_code'));
        $table->add(null, '<input type="button" class="button mainaction" id="2FA_change_qr_code" value="'.$this->gettext('show_qr_code').'"> 
                        <div id="2FA_qr_code" style="display: none; margin-top: 10px;"></div>');

        // new JS qr-code, without call to Google
        $this->include_script('2FA_qr_code.js');
    }

    // info
    $table->add(null, '<td><br>'.$this->gettext('msg_infor').'</td>');

    // button to setup all fields if doesn't exists secret
    $html_setup_all_fields = '';
    if (empty($data['secret'])) {
        $html_setup_all_fields = '<input type="button" class="button mainaction" id="2FA_setup_fields" value="'.$this->gettext('setup_all_fields').'">';
    }

    // Code verification section - moved before save button
    $html_check_code = '<div id="code_verification_section" style="margin: 20px 0; padding: 15px; border: 1px solid #ddd; background-color: #f9f9f9;">';
    $html_check_code .= '<h4>' . $this->gettext('verify_code') . '</h4>';
    $html_check_code .= '<p>' . $this->gettext('msg_help') . '</p>';
    $html_check_code .= '<input type="text" id="2FA_code_to_check" maxlength="10" placeholder="Enter 6-digit code" style="margin-right: 10px;">';
    $html_check_code .= '<input type="button" class="button mainaction" id="2FA_check_code" value="'.$this->gettext('check_code').'">';
    $html_check_code .= '<div id="code_verification_status" style="margin-top: 10px; font-weight: bold;"></div>';
    $html_check_code .= '</div>';

    // Use RoundCube's standard button helper - MOVED TO BOTTOM
    $save_button = $rcmail->output->button(array(
        'command' => 'plugin.twofactor_gauthenticator-save',
        'type' => 'input',
        'class' => 'button mainaction',
        'label' => 'save',
        'id' => '2FA_save_button',
        'disabled' => true  // Initially disabled
    ));

    // Build the table with the divs around it
    $out = html::div(
        array('class' => 'settingsbox'),
        html::tag('h3', array('id' => 'prefs-title', 'class' => ''), $this->gettext('twofactor_gauthenticator') . ' - ' . $rcmail->user->data['username']) .
        html::div(
            array('class' => 'boxcontent'),
            $table->show() .
            html::p(
                null,
                $html_setup_all_fields
            ) .
            $html_check_code .  // Code verification before save button
            html::p(
                array('style' => 'margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;'),
                $save_button  // Save button at the very bottom
            )
        )
    );

    // Construct the form
    $rcmail->output->add_gui_object('twofactor_gauthenticatorform', 'twofactor_gauthenticator-form');

    $out = $rcmail->output->form_tag(array(
        'id' => 'twofactor_gauthenticator-form',
        'name' => 'twofactor_gauthenticator-form',
        'method' => 'post',
        'action' => './?_task=settings&_action=plugin.twofactor_gauthenticator-save',
    ), $out);

    $out = "<div class='formcontainer'><div class='formcontent'>".$out."</div></div>";

    return $out;
}

    // used with ajax
    public function checkCode()
    {
        $code = rcube_utils::get_input_value('code', rcube_utils::INPUT_GET);
        //$secret = rcube_utils::get_input_value('secret', rcube_utils::INPUT_GET);
        $secret = rcube_utils::get_input_value('secret', rcube_utils::INPUT_GET);

        if (self::__checkCode($code, $secret)) {
            echo $this->gettext('code_ok');
        } else {
            echo $this->gettext('code_ko');
        }
        exit;
    }

    //------------- private methods

    // redirect to some RC task and remove 'login' user pref
    private function __goingRoundcubeTask($task = 'mail', $action = null)
    {

        $_SESSION['twofactor_gauthenticator_2FA_login'] = time();
        header('Location: ?_task='.$task . ($action ? '&_action='.$action : ''));
        exit;
    }

    private function __exitSession()
    {
        unset($_SESSION['twofactor_gauthenticator_login']);
        unset($_SESSION['twofactor_gauthenticator_2FA_login']);

        $rcmail = rcmail::get_instance();
        header('Location: ?_task=logout&_token='.$rcmail->get_request_token());
        exit;
    }

    private function __get2FAconfig()
    {
        $rcmail = rcmail::get_instance();
        $user = $rcmail->user;

        $arr_prefs = $user->get_prefs();
        $data = $arr_prefs['twofactor_gauthenticator'] ?? array();
        //decrypt
        if (!is_array($data) && $rcmail->config->get('twofactor_pref_encrypt'))
        {
            $cdata = json_decode($rcmail->decrypt($data));
            if ($cdata == null)
            {
                rcube::write_log('twofactor_gauthenticator',"WARN: Broken 2FA!, clearing...");
                $cdata = array();
            }
            $data = (array)$cdata;
        }
        return $data;
    }

    // we can set array to NULL to remove
    private function __set2FAconfig($data)
    {
        $rcmail = rcmail::get_instance();
        $user = $rcmail->user;

        $arr_prefs = $user->get_prefs();
        if ($data['activate'] != true) {
            // if deactivated, remove all data (secret was still generated and couldn't be removed)
            $data = array();
        }
        //encrypt
        if ($data && $rcmail->config->get('twofactor_pref_encrypt'))
        {
            $edata = $rcmail->encrypt(json_encode($data));
            $data = $edata != null ? $edata: $data;
        }
        $arr_prefs['twofactor_gauthenticator'] = $data;
        rcube::write_log('twofactor_gauthenticator',"WARN: 2FA may have changed!");
        return $user->save_prefs($arr_prefs);
    }

    private function __isRecoveryCode($code)
    {
        $prefs = self::__get2FAconfig();
        return in_array($code, $prefs['recovery_codes']);
    }

    private function __consumeRecoveryCode($code)
    {
        $prefs = self::__get2FAconfig();
        $prefs['recovery_codes'] = array_values(array_diff($prefs['recovery_codes'], array($code)));

        self::__set2FAconfig($prefs);
    }


    // GoogleAuthenticator class methods (see PHPGangsta/GoogleAuthenticator.php for more infor)
    // returns string
    private function __createSecret()
    {
        $ga = new PHPGangsta_GoogleAuthenticator();
        return $ga->createSecret();
    }

    // returns string
    private function __getSecret()
    {
        $prefs = self::__get2FAconfig();
        return $prefs['secret'];
    }

    // Commented. If you have problems with qr-code.js, you can uncomment and use this
    //
    // 	// returns string (url to img)
    // 	private function __getQRCodeGoogle()
    // 	{
    // 		$rcmail = rcmail::get_instance();

    // 		$ga = new PHPGangsta_GoogleAuthenticator();
    // 		return $ga->getQRCodeGoogleUrl($rcmail->user->data['username'], self::__getSecret(), 'RoundCube2FA');
    // 	}

    // returns boolean
    private function __checkCode($code, $secret = null)
    {
        $ga = new PHPGangsta_GoogleAuthenticator();
        return $ga->verifyCode(($secret ? $secret : self::__getSecret()), $code, 2);    // 2 = 2*30sec clock tolerance
    }


    // remember option by https://github.com/corrideat/
    private function __cookie($set = true)
    {
        $rcmail = rcmail::get_instance();
        $user_agent = hash_hmac('md5', filter_input(INPUT_SERVER, 'USER_AGENT') ?: "\0\0\0\0\0", $rcmail->config->get('des_key'));
        $key = hash_hmac('sha256', implode("\2\1\2", array($rcmail->user->data['username'], $this->__getSecret())), $rcmail->config->get('des_key'), true);
        $iv = hash_hmac('md5', implode("\3\2\3", array($rcmail->user->data['username'], $this->__getSecret())), $rcmail->config->get('des_key'), true);
        $name = hash_hmac('md5', $rcmail->user->data['username'], $rcmail->config->get('des_key'));

        if ($set) {
            $expires = time() + 2592000; // 30 days from now
            $rand = mt_rand();
            $signature = hash_hmac('sha512', implode("\1\0\1", array($rcmail->user->data['username'], $this->__getSecret(), $user_agent, $rand, $expires)), $rcmail->config->get('des_key'), true);
            $plain_content = sprintf("%d:%d:%s", $expires, $rand, $signature);
            $encrypted_content = openssl_encrypt($plain_content, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            if ($encrypted_content !== false) {
                $b64_encrypted_content = strtr(base64_encode($encrypted_content), '+/=', '-_,');
                rcube_utils::setcookie($name, $b64_encrypted_content, $expires);
                return true;
            }
            return false;
        } else {
            $b64_encrypted_content = filter_input(INPUT_COOKIE, $name, FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/[a-zA-Z0-9_-]+,{0,3}/')));
            if (is_string($b64_encrypted_content) && !empty($b64_encrypted_content) && strlen($b64_encrypted_content) % 4 === 0) {
                $encrypted_content = base64_decode(strtr($b64_encrypted_content, '-_,', '+/='), true);
                if ($encrypted_content !== false) {
                    $plain_content = openssl_decrypt($encrypted_content, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
                    if ($plain_content !== false) {
                        $now = time();
                        list($expires, $rand, $signature) = explode(':', $plain_content, 3);
                        if ($expires > $now && ($expires - $now) <= 2592000) {
                            $signature_verification = hash_hmac('sha512', implode("\1\0\1", array($rcmail->user->data['username'], $this->__getSecret(), $user_agent, $rand, $expires)), $rcmail->config->get('des_key'), true);
                            // constant time
                            $cmp = strlen($signature) ^ strlen($signature_verification);
                            $signature = $signature ^ $signature_verification;
                            for ($i = 0; $i < strlen($signature); $i++) {
                                $cmp += ord($signature [$i]);
                            }
                            return ($cmp === 0);
                        }
                    }
                }
            }
            return false;
        }
    }
    // END remember


    // log error into $_logs_file directory
    private function __logError()
    {
        rcube::write_log('twofactor_gauthenticator', "ERROR: 2FA fail - rip:". $_SERVER['HTTP_X_FORWARDED_FOR']." lip:".$_SERVER['REMOTE_ADDR']);
    }
}
