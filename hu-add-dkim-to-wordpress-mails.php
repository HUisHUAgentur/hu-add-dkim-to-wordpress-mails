<?php
/*
 * Plugin Name:       HU Add DKIM-Headers to Wordpress Mails
 * Description:       Add DKIM-Signature to the mails sent by wp_mail and manage DKIM keys.
 * Version:           2.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  cmb2
 * Author:            HUisHU. Digitale Kreativagentur GmbH
 * Author URI:        https://www.huishu-agentur.de
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */
 
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Holt eine Option und bevorzugt dabei Konstanten aus wp-config.php.
 */
function hu_ads_get_option( $key, $default = '' ) {
    $constant_map = [
        'dkim_domain'        => 'HU_ADS_DKIM_DOMAIN',
        'dkim_selector'      => 'HU_ADS_DKIM_SELECTOR',
        'dkim_passphrase'    => 'HU_ADS_DKIM_PASSPHRASE',
        'dkim_private_key'   => 'HU_ADS_DKIM_PRIVATE_KEY',
    ];

    if ( isset( $constant_map[$key] ) && defined( $constant_map[$key] ) ) {
        return constant( $constant_map[$key] );
    }
    
    if ( function_exists( 'cmb2_get_option' ) ) {
        return cmb2_get_option( 'hu_ads_options', $key, $default );
    }

    $opts = get_option( 'hu_ads_options', $default );
    $val = $default;
    if ( 'all' == $key ) {
        $val = $opts;
    } elseif ( is_array( $opts ) && array_key_exists( $key, $opts ) && false !== $opts[ $key ] ) {
        $val = $opts[ $key ];
    }
    return $val;
}

/**
 * Fügt die DKIM-Header zu PHPMailer hinzu, WENN die Funktion aktiv ist.
 */
function hu_ads_add_dkim_header_to_mails( $mail ){
    // NEU: Prüfen, ob die Signierung überhaupt aktiv ist.
    $is_active = hu_ads_get_option( 'dkim_active', false );

    $dkim_domain = hu_ads_get_option( 'dkim_domain', '' );
    $dkim_selector = hu_ads_get_option( 'dkim_selector', '' );
    $dkim_passphrase = hu_ads_get_option( 'dkim_passphrase', '' );
    $dkim_key = hu_ads_get_option(  'dkim_private_key', '' );
    
    // NEU: Die Bedingung wurde um die $is_active-Prüfung erweitert.
    if( ! $is_active || ! $dkim_domain || ! $dkim_selector || ! $dkim_key ){
        return $mail;
    }

    $mail->DKIM_domain = $dkim_domain;
    $mail->DKIM_private_string = $dkim_key;
    $mail->DKIM_selector = $dkim_selector;
    $mail->DKIM_passphrase = $dkim_passphrase;
    $mail->DKIM_identity = $mail->From;
    return $mail;
}
add_action('phpmailer_init', 'hu_ads_add_dkim_header_to_mails' );

/**
 * Registriert die CMB2-Einstellungsseite.
 */
function hu_ads_add_dkim_settings(){
    $cmb = new_cmb2_box(
        array(
            'id'           => 'hu_ads_options_page',
            'title'        => 'DKIM Settings',
            'object_types' => array( 'options-page' ),
            'option_key'   => 'hu_ads_options',
        )
    );

    $cmb->add_field(
        array(
            'name' => 'DKIM Konfiguration',
            'desc' => 'Hier kannst du deine E-Mails mit einer DKIM-Signatur versehen.',
            'type' => 'title',
            'id'   => 'hu_ads_title'
        )
    );
    
    // NEU: Checkbox zur Aktivierung der Signierung
    $cmb->add_field( array(
        'name' => 'DKIM-Signierung aktiv',
        'desc' => '<strong>DKIM für ausgehende E-Mails aktivieren.</strong><br>Entferne den Haken, während du die DNS-Einträge einrichtest. Setze ihn erst, wenn du sicher bist, dass der DNS-Eintrag korrekt und weltweit verfügbar ist.',
        'id'   => 'dkim_active',
        'type' => 'checkbox'
    ) );


    hu_ads_key_generation_and_dns_display( $cmb );

    $cmb->add_field(
        array(
            'name'    => 'Domain',
            'desc'    => 'Die Domain, von der die E-Mails versendet werden (z.B. "deine-website.de").',
            'id'      => 'dkim_domain',
            'type'    => 'text',
            'attributes' => array(
                'placeholder' => str_replace( 'www.', '', parse_url( home_url(), PHP_URL_HOST ) )
            )
        ) 
    );

    $cmb->add_field(
        array(
            'name'    => 'DKIM Selektor',
            'desc'    => 'Der Selektor für den DNS-Eintrag (z.B. "default" oder "mail"). Ergibt: <code>[selektor]._domainkey.deine-website.de</code>',
            'id'      => 'dkim_selector',
            'type'    => 'text',
            'default' => 'default',
        ) 
    );

    $cmb->add_field(
        array(
            'name'    => 'DKIM Passphrase (optional)',
            'desc'    => 'Falls dein privater Schlüssel mit einer Passphrase geschützt ist.',
            'id'      => 'dkim_passphrase',
            'type'    => 'text',
            'attributes' => array(
                'type' => 'password'
            )
        ) 
    );

    $cmb->add_field( 
        array(
            'name' => 'Privater DKIM Schlüssel',
            'id' => 'dkim_private_key',
            'type' => 'textarea',
            'desc' => 'Füge hier den kompletten privaten Schlüssel ein, inklusive "-----BEGIN PRIVATE KEY-----" und "-----END PRIVATE KEY-----".'
        )    
    );

    $cmb->add_field( 
        array(
            'id' => 'dkim_public_key',
            'type' => 'hidden',
        )    
    );
}
add_action( 'cmb2_admin_init', 'hu_ads_add_dkim_settings' );

/**
 * Zeigt den Key-Generator und den DNS-Eintrag an.
 */
function hu_ads_key_generation_and_dns_display( $cmb ) {
    $private_key = hu_ads_get_option( 'dkim_private_key' );
    $public_key = hu_ads_get_option( 'dkim_public_key' );
    $options_exist = ! empty( $private_key );
    
    if ( defined('HU_ADS_DKIM_PRIVATE_KEY') ) {
        $cmb->add_field( array(
            'name' => 'Status',
            'desc' => 'DKIM wird über Konstanten in deiner `wp-config.php` konfiguriert. Die Einstellungen hier werden ignoriert.',
            'type' => 'title',
            'id'   => 'hu_ads_constants_notice'
        ));
        return;
    }

    if ( ! $options_exist ) {
        if ( ! function_exists('openssl_pkey_new') ) {
            $cmb->add_field( array(
                'name' => 'Warnung!',
                'desc' => 'Die benötigte OpenSSL-Erweiterung ist auf deinem Server nicht verfügbar. Du kannst keine Schlüssel generieren und musst sie manuell erstellen und hier einfügen.',
                'type' => 'title',
                'id'   => 'hu_ads_openssl_warning'
            ));
        } else {
            $cmb->add_field( array(
                'name' => 'Schlüsselpaar generieren',
                'desc' => 'Es sind noch keine Schlüssel gespeichert. Du kannst hier ein neues Paar (Privater & Öffentlicher Schlüssel) generieren lassen.',
                'type' => 'title',
                'id'   => 'hu_ads_generator_title'
            ));
            $cmb->add_field( array(
                'name' => 'Generator Passphrase (optional)',
                'id'   => 'generator_passphrase',
                'type' => 'text',
                'desc' => 'Du kannst das neue Schlüsselpaar optional mit einer Passphrase schützen.',
                'attributes' => array('type' => 'password'),
                'save_field' => false, 
            ));
            $cmb->add_field( array(
                'id'   => 'generator_button',
                'type' => 'html_button',
                'desc' => 'Klicke, um die Schlüssel zu erstellen. Die Seite wird neu geladen und die Felder unten werden automatisch befüllt.',
                'button_text' => 'Schlüsselpaar jetzt generieren',
                'button_name' => 'hu_ads_generate_keys_submit',
            ));
        }
    }

    if ( $options_exist && ! empty( $public_key ) ) {
        $domain = hu_ads_get_option('dkim_domain');
        $selector = hu_ads_get_option('dkim_selector', 'default');
        
        $authoritative_ns_info = '<em>Konnte nicht ermittelt werden. Bitte prüfe die DNS-Einstellungen deiner Domain.</em>';
        if (function_exists('dns_get_record')) {
            $ns_records = @dns_get_record($domain, DNS_NS);
            if (!empty($ns_records)) {
                $ns_list = wp_list_pluck($ns_records, 'target');
                $authoritative_ns_info = 'Einer der folgenden Nameserver ist für deine Domain zuständig: <strong>' . implode(', ', $ns_list) . '</strong>';
            }
        }

        $public_key_clean = preg_replace( '/\s+/', '', str_replace( array('-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----'), '', $public_key ) );
        $dns_record_name = "{$selector}._domainkey.{$domain}";
        $dns_record_value = "v=DKIM1; k=rsa; p={$public_key_clean}";

        $cmb->add_field( array(
            'name' => 'Dein DNS-Eintrag',
            'desc' => 'Kopiere die folgenden Werte in den DNS-Manager deines Hosters, um DKIM zu aktivieren. ' . $authoritative_ns_info,
            'type' => 'title',
            'id'   => 'hu_ads_dns_title'
        ));

        $cmb->add_field( array(
            'name' => 'Typ',
            'id'   => 'dns_type',
            'type' => 'text',
            'default' => 'TXT',
            'attributes' => array('readonly' => 'readonly'),
        ));
        $cmb->add_field( array(
            'name' => 'Host / Name',
            'id'   => 'dns_name',
            'type' => 'text',
            'default' => $dns_record_name,
            'attributes' => array('readonly' => 'readonly'),
        ));
        $cmb->add_field( array(
            'name' => 'Wert / Inhalt',
            'id'   => 'dns_value',
            'type' => 'textarea',
            'default' => $dns_record_value,
            'attributes' => array('readonly' => 'readonly'),
        ));
    }
}

/**
 * Eigener Feld-Typ für HTML-Buttons in CMB2.
 */
function hu_ads_render_html_button_field( $field, $escaped_value, $object_id, $object_type, $field_type_object ) {
    echo $field_type_object->description();
    echo '<p><button type="submit" class="button button-primary" name="' . esc_attr( $field->args( 'button_name' ) ) . '">' . esc_html( $field->args( 'button_text' ) ) . '</button></p>';
}
add_action( 'cmb2_render_html_button', 'hu_ads_render_html_button_field', 10, 5 );

/**
 * Verarbeitet die Aktion zur Key-Generierung auf der Admin-Seite.
 */
function hu_ads_admin_page_actions() {
    if ( ! isset( $_POST['object_id'] ) || $_POST['object_id'] !== 'hu_ads_options' ) {
        return;
    }

    if ( isset( $_POST['hu_ads_generate_keys_submit'] ) ) {
        $passphrase = isset( $_POST['generator_passphrase'] ) ? sanitize_text_field( $_POST['generator_passphrase'] ) : '';

        $config = array("digest_alg" => "sha256", "private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA);
        $res = openssl_pkey_new( $config );

        if ( !$res ) {
            add_settings_error('hu_ads_options', 'keys_generated_error', 'Fehler bei der Schlüsselgenerierung!', 'error');
            return;
        }

        openssl_pkey_export( $res, $private_key, $passphrase );
        $public_key_details = openssl_pkey_get_details( $res );
        $public_key = $public_key_details["key"];

        $options = get_option( 'hu_ads_options', [] );
        $options['dkim_private_key'] = $private_key;
        $options['dkim_public_key'] = $public_key;
        $options['dkim_passphrase'] = $passphrase;
        update_option( 'hu_ads_options', $options );
        
        add_settings_error('hu_ads_options', 'keys_generated_success', 'Schlüsselpaar erfolgreich generiert und gespeichert!', 'success');
    }
}
add_action( 'admin_init', 'hu_ads_admin_page_actions', 20 );