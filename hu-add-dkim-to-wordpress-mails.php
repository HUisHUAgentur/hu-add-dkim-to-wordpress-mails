<?php
/*
 * Plugin Name:       HU Add DKIM-Headers to Wordpress Mails
 * Description:       Fügt DKIM-Signaturen zu wp_mail hinzu. Mit Key-Abgleich und flexiblem Testversand.
 * Version:           3.5
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            HUisHU. Digitale Kreativagentur GmbH
 * License:           GPL v2 or later
 */

if (!defined('ABSPATH')) exit;

/**
 * 1. OPTIONS-HANDLING
 */
function hu_ads_get_opt( $key, $default = '' ) {
    $constant_map = [
        'dkim_domain'      => 'HU_ADS_DKIM_DOMAIN',
        'dkim_selector'    => 'HU_ADS_DKIM_SELECTOR',
        'dkim_private_key' => 'HU_ADS_DKIM_PRIVATE_KEY',
    ];

    if ( isset( $constant_map[$key] ) && defined( $constant_map[$key] ) ) {
        return constant( $constant_map[$key] );
    }

    $opts = get_option( 'hu_ads_settings', [] );
    return isset( $opts[$key] ) ? $opts[$key] : $default;
}

/**
 * 2. DKIM IN PHPMYMAILER EINSPEISEN
 */
add_action( 'phpmailer_init', function( $phpmailer ) {
    if ( ! hu_ads_get_opt( 'dkim_active' ) ) return;

    $domain   = hu_ads_get_opt( 'dkim_domain' );
    $selector = hu_ads_get_opt( 'dkim_selector' );
    $key      = hu_ads_get_opt( 'dkim_private_key' );

    if ( $domain && $selector && $key ) {
        $phpmailer->DKIM_domain         = $domain;
        $phpmailer->DKIM_selector       = $selector;
        $phpmailer->DKIM_private_string = $key;
        $phpmailer->DKIM_passphrase     = hu_ads_get_opt( 'dkim_passphrase', '' );
        $phpmailer->DKIM_identity       = $phpmailer->From;
    }
});

/**
 * 3. ADMIN MENÜ & SETTINGS
 */
add_action( 'admin_menu', function() {
    add_options_page( 'DKIM Settings', 'DKIM Mailer', 'manage_options', 'hu_ads_dkim', 'hu_ads_render_admin_page' );
});

add_action( 'admin_init', function() {
    register_setting( 'hu_ads_group', 'hu_ads_settings' );
    hu_ads_handle_admin_actions();
});

/**
 * 4. AKTIONEN (KEY-GEN & TEST-MAIL)
 */
function hu_ads_handle_admin_actions() {
    if ( ! isset( $_POST['hu_ads_action'] ) ) return;
    check_admin_referer( 'hu_ads_action_nonce' );

    // Generierung
    if ( $_POST['hu_ads_action'] === 'generate_keys' ) {
        $res = openssl_pkey_new([
            "digest_alg" => "sha256",
            "private_key_bits" => 2048,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ]);

        if ( $res ) {
            openssl_pkey_export( $res, $privKey );
            $pubKey = openssl_pkey_get_details($res)['key'];

            $opts = get_option( 'hu_ads_settings', [] );
            $opts['dkim_private_key'] = $privKey;
            $opts['dkim_public_key']  = $pubKey;
            update_option( 'hu_ads_settings', $opts );
            add_settings_error( 'hu_ads_msg', 'gen', 'Schlüsselpaar generiert! Bitte speichere die Einstellungen, falls du die Domain geändert hast.', 'success' );
        }
    }

    // Test-Mail
    if ( $_POST['hu_ads_action'] === 'send_test' ) {
        $to = sanitize_email( $_POST['test_email_recipient'] );
        if ( ! is_email( $to ) ) {
            add_settings_error( 'hu_ads_msg', 'test', 'Ungültige Test-E-Mail-Adresse.', 'error' );
            return;
        }

        $subject = 'DKIM Test-Mail von ' . get_bloginfo('name');
        $message = "Diese Mail testet die DKIM-Einstellungen.\nDomain: " . hu_ads_get_opt('dkim_domain') . "\nSelektor: " . hu_ads_get_opt('dkim_selector');
        
        if ( wp_mail( $to, $subject, $message ) ) {
            add_settings_error( 'hu_ads_msg', 'test', 'Test-Mail erfolgreich an ' . $to . ' gesendet.', 'success' );
        } else {
            add_settings_error( 'hu_ads_msg', 'test', 'Versand fehlgeschlagen. Prüfe deine Server-Logs.', 'error' );
        }
    }
}

/**
 * 5. ADMIN PAGE RENDERN
 */
function hu_ads_render_admin_page() {
    $pubKeyLocal = hu_ads_get_opt('dkim_public_key');
    $domain      = hu_ads_get_opt('dkim_domain');
    $selector    = hu_ads_get_opt('dkim_selector', 'default');
    
    // DNS VALIDIERUNG LOGIK
    $dns_status_msg = "❌ Kein DNS-Eintrag gefunden.";
    $status_color   = "red";
    
    if ( $domain && $pubKeyLocal ) {
        $host = $selector . '._domainkey.' . $domain;
        $records = dns_get_record( $host, DNS_TXT );
        
        if ( !empty($records) ) {
            $dns_status_msg = "⚠️ DNS-Eintrag gefunden, aber Schlüssel stimmt nicht überein.";
            $status_color   = "orange";
            
            // Bereinige den lokalen Public Key für den Vergleich
            $cleanLocal = preg_replace('/\s+/', '', str_replace(["-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----"], "", $pubKeyLocal));
            
            foreach ( $records as $r ) {
                if ( isset($r['txt']) && strpos($r['txt'], $cleanLocal) !== false ) {
                    $dns_status_msg = "✅ Korrekter DNS-Eintrag erkannt!";
                    $status_color   = "green";
                    break;
                }
            }
        }
    }

    ?>
    <div class="wrap">
        <h1>HU DKIM Mailer</h1>
        <?php settings_errors('hu_ads_msg'); ?>

        <form method="post" action="options.php">
            <?php settings_fields( 'hu_ads_group' ); ?>
            <table class="form-table">
                <tr>
                    <th>DKIM Aktivieren</th>
                    <td>
                        <input type="checkbox" name="hu_ads_settings[dkim_active]" value="1" <?php checked(1, hu_ads_get_opt('dkim_active')); ?> />
                        <p class="description">Erst aktivieren, wenn der DNS-Status grün zeigt.</p>
                    </td>
                </tr>
                <tr>
                    <th>Domain</th>
                    <td><input type="text" name="hu_ads_settings[dkim_domain]" value="<?php echo esc_attr($domain); ?>" class="regular-text" placeholder="deine-domain.de" /></td>
                </tr>
                <tr>
                    <th>Selektor</th>
                    <td><input type="text" name="hu_ads_settings[dkim_selector]" value="<?php echo esc_attr($selector); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th>Privater Schlüssel</th>
                    <td><textarea name="hu_ads_settings[dkim_private_key]" rows="8" class="large-text code" style="font-size:11px;"><?php echo esc_textarea(hu_ads_get_opt('dkim_private_key')); ?></textarea></td>
                </tr>
                <tr style="display:none;">
                    <td><input type="hidden" name="hu_ads_settings[dkim_public_key]" value="<?php echo esc_attr($pubKeyLocal); ?>" /></td>
                </tr>
            </table>
            <?php submit_button('Einstellungen speichern'); ?>
        </form>

        <hr>

        <h2>Werkzeuge & Live-Check</h2>
        <form method="post" action="">
            <?php wp_nonce_field( 'hu_ads_action_nonce' ); ?>
            <table class="form-table">
                <?php if ( $pubKeyLocal ): ?>
                <tr>
                    <th>DNS Konfiguration</th>
                    <td>
                        <p>Lege diesen TXT-Eintrag bei deinem Provider an:</p>
                        <code><strong>Host:</strong> <?php echo esc_html($selector); ?>._domainkey.<?php echo esc_html($domain); ?></code><br><br>
                        <code><strong>Wert:</strong> v=DKIM1; k=rsa; p=<?php echo preg_replace('/\s+/', '', str_replace(["-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----"], "", $pubKeyLocal)); ?></code>
                        
                        <div style="margin-top:20px; padding:15px; background:#fff; border-left:4px solid <?php echo $status_color; ?>;">
                            <strong>Status:</strong> <?php echo $dns_status_msg; ?>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th>Schlüssel verwalten</th>
                    <td>
                        <button type="submit" name="hu_ads_action" value="generate_keys" class="button">Neues Schlüsselpaar generieren</button>
                        <p class="description">Vorsicht: Überschreibt vorhandene Schlüssel im Formular.</p>
                    </td>
                </tr>
                <tr>
                    <th>Test-Versand</th>
                    <td>
                        <input type="email" name="test_email_recipient" value="<?php echo esc_attr(get_option('admin_email')); ?>" class="regular-text" placeholder="test@empfaenger.de" />
                        <button type="submit" name="hu_ads_action" value="send_test" class="button button-primary">Test-Mail senden</button>
                    </td>
                </tr>
            </table>
        </form>
    </div>
    <?php
}
