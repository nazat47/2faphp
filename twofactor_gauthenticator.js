if (window.rcmail) {
	rcmail.addEventListener('init', function(evt) {

	  // ripped from PHPGansta/GoogleAuthenticator.php
		function createSecret(secretLength) {
			if(!secretLength) secretLength = 16;

			var lookupTable = new Array(
		            'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', //  7
		            'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', // 15
		            'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', // 23
		            'Y', 'Z', '2', '3', '4', '5', '6', '7' // 31
		            //'='  // padding char
		        );

			var secret = '';
			var random = new Uint8Array(secretLength);
			var cryptoapi = window.crypto || window.msCrypto; // Support IE11 for now
			cryptoapi.getRandomValues(random);
			for (var i = 0; i < secretLength; i++) {
				secret += lookupTable[random[i]%lookupTable.length];
			}
			return secret;
		}

		// populate all fields
		function setup2FAfields() {
			if($('#2FA_secret').get(0).value) return;
			
			
			$('#twofactor_gauthenticator-form :input').each(function(){
				if($(this).get(0).type == 'password') $(this).get(0).type = 'text';
			});
			
			// secret button
			$('#2FA_create_secret').prop('id', '2FA_change_secret');
			$('#2FA_change_secret').get(0).value = rcmail.gettext('hide_secret', 'twofactor_gauthenticator');
			$('#2FA_change_secret').click(click2FA_change_secret);
			$('#2FA_change_secret').removeAttr('disabled');		// now we disable all buttons previosly and user needs to "setup_all_fields"

			$('#2FA_activate').prop('checked', true);
			$('#2FA_show_recovery_codes').get(0).value = rcmail.gettext('hide_recovery_codes', 'twofactor_gauthenticator');
			$('#2FA_show_recovery_codes').removeAttr('disabled');		// now we disable all buttons previosly and user needs to "setup_all_fields"
			$('#2FA_qr_code').slideDown();
			
			$('#2FA_secret').get(0).value = createSecret();
			$("[name^='2FA_recovery_codes']").each(function() {
				$(this).get(0).value = createSecret(10);
			});
			
			// add qr-code before msg_infor
			var url_qr_code_values = 'otpauth://totp/' +$('#prefs-title').html().split(/ - /)[1]+ '?secret=' +$('#2FA_secret').get(0).value +'&issuer=RoundCube2FA%20'+window.location.hostname;
			$('table tr:last').before('<tr><td>' +rcmail.gettext('qr_code', 'twofactor_gauthenticator')+ '</td><td><input type="button" class="button mainaction btn btn-primary" id="2FA_change_qr_code" value="' 
					+rcmail.gettext('hide_qr_code', 'twofactor_gauthenticator')+ '"><div id="2FA_qr_code" style="display: visible; margin-top: 10px;"></div></td></tr>');
			
			var qrcode = new QRCode(document.getElementById("2FA_qr_code"), {
			    text: url_qr_code_values,
			    width: 200,
			    height: 200,
			    colorDark : "#000000",
			    colorLight : "#ffffff",
			    correctLevel : QRCode.CorrectLevel.L		// like charts.googleapis.com
			});
			
			$('#2FA_change_qr_code').click(click2FA_change_qr_code);
			$('#2FA_qr_code').prop('title', '');    // enjoy the silence (qrcode.js uses text to set title)

			// white frame to dark mode, only to img generated
			$('#2FA_qr_code').children('img').css({
							'background-color': '#fff',
							padding: '4px'
			});

			// disable save button. It needs check code to enabled again
			$('#2FA_setup_fields').prev().attr('disabled','disabled').attr('title', rcmail.gettext('check_code_to_activate', 'twofactor_gauthenticator'));
      alert(rcmail.gettext('check_code_to_activate', 'twofactor_gauthenticator'));
		}

	  $('#2FA_setup_fields').click(function(){
		  setup2FAfields();
	  });

	  // to show/hide secret
	  click2FA_change_secret = function(){
		  if($('#2FA_secret').get(0).type == 'text') {
			  $('#2FA_secret').get(0).type = 'password';
			  $('#2FA_change_secret').get(0).value = rcmail.gettext('show_secret', 'twofactor_gauthenticator');
		  }
		  else
		  {
			  $('#2FA_secret').get(0).type = 'text';
			  $('#2FA_change_secret').get(0).value = rcmail.gettext('hide_secret', 'twofactor_gauthenticator');
		  }
	  };
	  $('#2FA_change_secret').click(click2FA_change_secret);

	  // to show/hide recovery_codes
	  $('#2FA_show_recovery_codes').click(function(){

		  if($("[name^='2FA_recovery_codes']")[0].type == 'text') {
			  $("[name^='2FA_recovery_codes']").each(function() {
				  $(this).get(0).type = 'password';
			  });
			  $('#2FA_show_recovery_codes').get(0).value = rcmail.gettext('show_recovery_codes', 'twofactor_gauthenticator');
		  }
		  else {
			  $("[name^='2FA_recovery_codes']").each(function() {
				  $(this).get(0).type = 'text';
			  });
			  $('#2FA_show_recovery_codes').get(0).value = rcmail.gettext('hide_recovery_codes', 'twofactor_gauthenticator');
		  }
	  });

	  // to show/hide qr_code
	  click2FA_change_qr_code = function(){
		  if( $('#2FA_qr_code').is(':visible') ) {
			  $('#2FA_qr_code').slideUp();
			  $(this).get(0).value = rcmail.gettext('show_qr_code', 'twofactor_gauthenticator');
		  }
		  else {
			$('#2FA_qr_code').slideDown();
		  	$(this).get(0).value = rcmail.gettext('hide_qr_code', 'twofactor_gauthenticator');
		  }
	  }
	  $('#2FA_change_qr_code').click(click2FA_change_qr_code);

	  // create secret
	  $('#2FA_create_secret').click(function(){
		  $('#2FA_secret').get(0).value = createSecret();
	  });

		// ajax
		$('#2FA_check_code').click(function(){
			url = "./?_action=plugin.twofactor_gauthenticator-checkcode&code=" +$('#2FA_code_to_check').val() + '&secret='+$('#2FA_secret').val();
			$.post(url, function(data){
					alert(data);
					if(data == rcmail.gettext('code_ok', 'twofactor_gauthenticator'))
						$('#2FA_setup_fields').prev().removeAttr('disabled');
						
				});
		});

    // Define Variables
    var tabtwofactorgauthenticator = $('<li>')
      .attr('id', 'settingstabplugintwofactor_gauthenticator')
      .addClass('listitem twofactor_gauthenticator');
    var button = $('<a>')
      .attr('href', rcmail.env.comm_path + '&_action=plugin.twofactor_gauthenticator')
      .html(rcmail.gettext('twofactor_gauthenticator', 'twofactor_gauthenticator'))
      .attr('role', 'button') 
      //.attr('onclick', 'return rcmail.command(\'show\', \'plugin.twofactor_gauthenticator\', this, event)') 
      .attr('tabindex', '0') 
      .attr('aria-disabled', 'false')
      .appendTo(tabtwofactorgauthenticator);

    // Button & Register commands
    rcmail.add_element(tabtwofactorgauthenticator, 'tabs');
    rcmail.register_command('plugin.twofactor_gauthenticator', function() { rcmail.goto_url('plugin.twofactor_gauthenticator') }, true);
    rcmail.register_command('plugin.twofactor_gauthenticator-save', function() {
    	if(!$('#2FA_secret').get(0).value) {
    		$('#2FA_secret').get(0).value = createSecret();
    	}
        rcmail.gui_objects.twofactor_gauthenticatorform.submit();
    }, true);
  });
}
// Enhanced JavaScript for twofactor_gauthenticator plugin
// Add this to your twofactor_gauthenticator.js file

$(document).ready(function() {
    
    // Function to validate all required fields
    function validateFields() {
        var secret = $('#2FA_secret').val().trim();
        var activate = $('#2FA_activate').is(':checked');
        var allRecoveryCodesFilled = true;
        var hasValidCode = false;
        
        // Check if all recovery code fields are filled
        $('input[name="2FA_recovery_codes[]"]').each(function() {
            if ($(this).val().trim() === '') {
                allRecoveryCodesFilled = false;
                return false; // break the loop
            }
        });
        
        // Check if a test code has been verified (you might need to track this)
        hasValidCode = $('#code_verification_status').data('verified') === true;
        
        // Enable/disable save button based on validation
        var isValid = secret !== '' && activate && allRecoveryCodesFilled && hasValidCode;
        
        if (isValid) {
            $('input[command="plugin.twofactor_gauthenticator-save"]').prop('disabled', false)
                .removeClass('button-disabled').addClass('button mainaction');
            $('#validation-message').hide();
        } else {
            $('input[command="plugin.twofactor_gauthenticator-save"]').prop('disabled', true)
                .removeClass('mainaction').addClass('button-disabled');
            showValidationMessage();
        }
        
        return isValid;
    }
    
    // Show validation message
    function showValidationMessage() {
        var messages = [];
        
        if ($('#2FA_secret').val().trim() === '') {
            messages.push('Secret key is required');
        }
        
        if (!$('#2FA_activate').is(':checked')) {
            messages.push('2FA must be activated');
        }
        
        var emptyRecoveryCodes = 0;
        $('input[name="2FA_recovery_codes[]"]').each(function() {
            if ($(this).val().trim() === '') {
                emptyRecoveryCodes++;
            }
        });
        
        if (emptyRecoveryCodes > 0) {
            messages.push(`${emptyRecoveryCodes} recovery code(s) missing`);
        }
        
        if ($('#code_verification_status').data('verified') !== true) {
            messages.push('Please verify a test code from your authenticator app');
        }
        
        var messageHtml = '<div id="validation-message" class="boxwarning" style="margin: 10px 0;">' +
                         '<strong>Complete setup required:</strong><ul>';
        
        $.each(messages, function(index, message) {
            messageHtml += '<li>' + message + '</li>';
        });
        
        messageHtml += '</ul></div>';
        
        $('#validation-message').remove();
        $('#twofactor_gauthenticator-form .formcontent').prepend(messageHtml);
    }
    
    // Enhanced code verification with visual feedback
    function enhancedCheckCode() {
        var code = $('#2FA_code_to_check').val();
        var secret = $('#2FA_secret').val();
        
        if (!code || !secret) {
            alert('Please enter both a secret and a verification code.');
            return;
        }
        
        // Show loading state
        $('#2FA_check_code').prop('disabled', true).val('Checking...');
        
        $.ajax({
            url: '?_task=settings&_action=plugin.twofactor_gauthenticator-checkcode',
            type: 'GET',
            data: {
                code: code,
                secret: secret
            },
            success: function(response) {
                var $statusDiv = $('#code_verification_status');
                if (!$statusDiv.length) {
                    $statusDiv = $('<div id="code_verification_status" style="margin: 10px 0;"></div>');
                    $('#2FA_code_to_check').after($statusDiv);
                }
                
                if (response.indexOf('code_ok') !== -1 || response.indexOf('Code is valid') !== -1) {
                    $statusDiv.html('<span style="color: green; font-weight: bold;">✓ Code verified successfully!</span>')
                            .data('verified', true);
                } else {
                    $statusDiv.html('<span style="color: red; font-weight: bold;">✗ Invalid code. Please try again.</span>')
                            .data('verified', false);
                }
                
                // Re-validate after code check
                validateFields();
            },
            error: function() {
                alert('Error checking code. Please try again.');
            },
            complete: function() {
                $('#2FA_check_code').prop('disabled', false).val(rcmail.get_label('check_code', 'twofactor_gauthenticator'));
            }
        });
    }
    
    // Enhanced setup all fields function
    function setupAllFields() {
        // Generate secret
        var secret = generateRandomSecret(16);
        $('#2FA_secret').val(secret);
        
        // Generate recovery codes
        $('input[name="2FA_recovery_codes[]"]').each(function() {
            $(this).val(generateRecoveryCode());
        });
        
        // Enable activation
        $('#2FA_activate').prop('checked', true);
        
        // Enable buttons that were previously disabled
        $('#2FA_create_secret, #2FA_show_recovery_codes, #2FA_change_qr_code').prop('disabled', false);
        
        // Generate and show QR code
        if (typeof generateQRCode === 'function') {
            generateQRCode();
        }
        
        // Validate after setup
        setTimeout(validateFields, 100);
    }
    
    // Generate random secret (simplified version)
    function generateRandomSecret(length) {
        var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        var result = '';
        for (var i = 0; i < length; i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
    }
    
    // Generate recovery code
    function generateRecoveryCode() {
        return Math.random().toString(36).substring(2, 12).toUpperCase();
    }
    
    // Event listeners
    $('#2FA_secret, #2FA_activate, input[name="2FA_recovery_codes[]"]').on('change keyup', validateFields);
    $('#2FA_check_code').on('click', enhancedCheckCode);
    $('#2FA_setup_fields').on('click', setupAllFields);
    
    // Prevent form submission if validation fails
    $('#twofactor_gauthenticator-form').on('submit', function(e) {
        if (!validateFields()) {
            e.preventDefault();
            alert('Please complete all required fields before saving.');
            return false;
        }
    });
    
    // Initial validation
    setTimeout(validateFields, 500);
    
    // Add CSS for disabled button
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            .button-disabled {
                opacity: 0.6;
                cursor: not-allowed !important;
                background-color: #ccc !important;
                color: #666 !important;
            }
            .button-disabled:hover {
                background-color: #ccc !important;
            }
            #validation-message li {
                margin: 5px 0;
            }
        `)
        .appendTo('head');
});
