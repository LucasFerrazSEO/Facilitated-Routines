<?php
/**
 * Plugin Name: Facilitated Routines
 * Description: Automatize tarefas repetitivas e otimize seu Wordpress
 * Version:     1.6.1
 * Author:      Lucas Ferraz SEO
 * Author URI:  https://lucasferrazseo.com
 * Requires at least: 6.8.2
 * Requires PHP: 8.0
 * Text Domain: facilitated-routines
 * Domain Path: /languages
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'FACILITATED_ROUTINES_MIN_WP',  '6.8.2' );
define( 'FACILITATED_ROUTINES_MIN_PHP', '8.0' );
define( 'FACILITATED_ROUTINES_VERSION', '1.6.1' );

function facilitated_routines_requirements_met() {
    global $wp_version;
    if ( version_compare( PHP_VERSION, FACILITATED_ROUTINES_MIN_PHP, '<' ) ) {
        return false;
    }
    if ( isset( $wp_version ) && version_compare( $wp_version, FACILITATED_ROUTINES_MIN_WP, '<' ) ) {
        return false;
    }
    return true;
}

function facilitated_routines_activation_check() {
    if ( ! facilitated_routines_requirements_met() ) {
        $msg  = '<h1>' . esc_html__( 'Requirements not met', 'facilitated-routines' ) . '</h1>';
        $msg .= '<p>' . sprintf(
            esc_html__( 'This plugin requires WordPress %1$s or greater and PHP %2$s or greater.', 'facilitated-routines' ),
            esc_html( FACILITATED_ROUTINES_MIN_WP ),
            esc_html( FACILITATED_ROUTINES_MIN_PHP )
        ) . '</p>';
        $msg .= '<p>' . esc_html__( 'Update your environment and try again.', 'facilitated-routines' ) . '</p>';
        if ( function_exists( 'deactivate_plugins' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
        }
        wp_die( $msg, esc_html__( 'Requirements not met', 'facilitated-routines' ), array( 'back_link' => true ) );
    }
}
register_activation_hook( __FILE__, 'facilitated_routines_activation_check' );

function facilitated_routines_admin_notice() {
    if ( facilitated_routines_requirements_met() ) { return; }
    printf(
        '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
        esc_html__( 'Facilitated Routines', 'facilitated-routines' ),
        sprintf(
            esc_html__( 'requires WordPress %1$s and PHP %2$s.', 'facilitated-routines' ),
            esc_html( FACILITATED_ROUTINES_MIN_WP ),
            esc_html( FACILITATED_ROUTINES_MIN_PHP )
        )
    );
}
add_action( 'admin_notices', 'facilitated_routines_admin_notice' );

// Idiomas: EN default; pt_BR quando WP em pt_BR; es_ES quando WP em espanhol; demais usam EN
add_action( 'plugins_loaded', function() {
    $domain   = 'facilitated-routines';
    $lang_dir = plugin_dir_path( __FILE__ ) . 'languages/';
    $locale   = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
    if ( 'pt_BR' === $locale ) {
        $mofile = $lang_dir . $domain . '-pt_BR.mo';
        if ( file_exists( $mofile ) ) {
            load_textdomain( $domain, $mofile );
        }
        return;
    }
    if ( 0 === strpos( $locale, 'es' ) ) {
        $mofile = $lang_dir . $domain . '-es_ES.mo';
        if ( file_exists( $mofile ) ) {
            load_textdomain( $domain, $mofile );
        }
        return;
    }
} );

if ( ! facilitated_routines_requirements_met() ) { return; }

final class Facilitated_Routines {
    const OPTION_GROUP                 = 'facilitated_routines';
    const OPTION_KEY_RENAME_ON_UPLOAD  = 'facilitated_routines_rename_on_upload'; // default 1
    const OPTION_KEY_AUTO_UPDATE       = 'facilitated_routines_auto_update';      // default 1

    const GITHUB_OWNER = 'LucasFerrazSEO';
    const GITHUB_REPO  = 'Facilitated-Routines';

    public function __construct() {
        // Link de configurações na lista de plugins
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'add_settings_link' ] );

        // Página e campos de configurações
        add_action( 'admin_menu',  [ $this, 'register_settings_page' ] );
        add_action( 'admin_init',  [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Ajax para renomeação em massa
        add_action( 'wp_ajax_facilitated_routines_prepare_bulk', [ $this, 'ajax_prepare_bulk' ] );
        add_action( 'wp_ajax_facilitated_routines_process_bulk', [ $this, 'ajax_process_bulk' ] );

        // Renomear ao publicar ou atualizar
        add_action( 'save_post', [ $this, 'rename_featured_on_save' ], 20, 3 );

        // Atualizações automáticas via GitHub Releases sem uso de token
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugins_api' ], 10, 3 );
        add_filter( 'auto_update_plugin', [ $this, 'maybe_auto_update' ], 10, 2 );
    }

    /* =================== Update checker =================== */
    private function get_latest_release() {
        $cache_key = 'fr_latest_release';
        $cached = get_site_transient( $cache_key );
        if ( $cached && is_array( $cached ) ) {
            return $cached;
        }
        $url = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', self::GITHUB_OWNER, self::GITHUB_REPO );

        $headers = [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
        ];

        $response = wp_remote_get( $url, [ 'headers' => $headers, 'timeout' => 15 ] );
        if ( is_wp_error( $response ) ) { return null; }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) { return null; }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) ) { return null; }

        $tag = isset( $data['tag_name'] ) ? (string) $data['tag_name'] : '';
        $version = ltrim( $tag, 'vV' );

        $package = '';
        if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
            foreach ( $data['assets'] as $asset ) {
                if ( ! empty( $asset['browser_download_url'] ) && is_string( $asset['browser_download_url'] ) ) {
                    $name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
                    if ( $name === 'facilitated-routines.zip' || ( substr( strtolower( $asset['browser_download_url'] ), -4 ) === '.zip' ) ) {
                        $package = $asset['browser_download_url'];
                        break;
                    }
                }
            }
        }
        if ( $package === '' && ! empty( $data['zipball_url'] ) ) {
            $package = (string) $data['zipball_url'];
        }

        $release = [
            'version' => $version,
            'package' => $package,
            'url'     => isset( $data['html_url'] ) ? (string) $data['html_url'] : '',
            'body'    => isset( $data['body'] ) ? (string) $data['body'] : '',
        ];

        set_site_transient( $cache_key, $release, HOUR_IN_SECONDS );
        return $release;
    }

    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) { return $transient; }

        $release = $this->get_latest_release();
        if ( ! $release || empty( $release['version'] ) ) { return $transient; }

        $current = FACILITATED_ROUTINES_VERSION;
        if ( version_compare( $release['version'], $current, '<=' ) ) { return $transient; }

        $plugin_file = plugin_basename( __FILE__ );
        $obj = (object) [
            'slug'        => 'facilitated-routines',
            'plugin'      => $plugin_file,
            'new_version' => $release['version'],
            'tested'      => get_bloginfo( 'version' ),
            'requires'    => FACILITATED_ROUTINES_MIN_WP,
            'url'         => 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
            'package'     => $release['package'],
        ];

        $transient->response[ $plugin_file ] = $obj;
        return $transient;
    }

    public function plugins_api( $res, $action, $args ) {
        if ( $action !== 'plugin_information' ) { return $res; }
        if ( empty( $args->slug ) || $args->slug !== 'facilitated-routines' ) { return $res; }

        $release = $this->get_latest_release();
        if ( ! $release ) { return $res; }

        $info = (object) [
            'name'          => __( 'Facilitated Routines', 'facilitated-routines' ),
            'slug'          => 'facilitated-routines',
            'version'       => $release['version'] ?: FACILITATED_ROUTINES_VERSION,
            'author'        => '<a href="https://lucasferrazseo.com">Lucas Ferraz SEO</a>',
            'homepage'      => 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
            'requires'      => FACILITATED_ROUTINES_MIN_WP,
            'tested'        => get_bloginfo( 'version' ),
            'requires_php'  => FACILITATED_ROUTINES_MIN_PHP,
            'download_link' => $release['package'],
            'sections'      => [
                'description' => wp_kses_post( nl2br( $release['body'] ?: __( 'Automated updates via GitHub Releases.', 'facilitated-routines' ) ) ),
            ],
        ];
        return $info;
    }

    public function maybe_auto_update( $update, $item ) {
        $enabled = (int) get_option( self::OPTION_KEY_AUTO_UPDATE, 1 );
        if ( ! isset( $item->slug ) ) { return $update; }
        if ( $item->slug === 'facilitated-routines' ) {
            return (bool) $enabled;
        }
        return $update;
    }

    /* =================== Renomear somente ao publicar ou atualizar =================== */
    public function rename_featured_on_save( $post_id, $post, $update ) {
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) { return; }
        if ( wp_is_post_revision( $post_id ) ) { return; }
        if ( 'auto-draft' === $post->post_status ) { return; }

        $enabled = (int) get_option( self::OPTION_KEY_RENAME_ON_UPLOAD, 1 );
        if ( ! $enabled ) { return; }

        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( ! $thumb_id ) { return; }

        $this->rename_attachment_to_post_title( $thumb_id, $post_id );
    }

    /* =================== Configurações =================== */
    public function add_settings_link( $links ) {
        $url = admin_url( 'options-general.php?page=facilitated-routines' );
        $text = esc_html__( 'Settings', 'facilitated-routines' );
        array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . $text . '</a>' );
        return $links;
    }

    public function register_settings_page() {
        add_options_page(
            __( 'Facilitated Routines', 'facilitated-routines' ),
            __( 'Facilitated Routines', 'facilitated-routines' ),
            'manage_options',
            'facilitated-routines',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings() {
        register_setting( self::OPTION_GROUP, self::OPTION_KEY_RENAME_ON_UPLOAD, [
            'type' => 'boolean', 'default' => 1,
            'sanitize_callback' => function( $v ){ return (int) (bool) $v; }
        ] );
        register_setting( self::OPTION_GROUP, self::OPTION_KEY_AUTO_UPDATE, [
            'type' => 'boolean', 'default' => 1,
            'sanitize_callback' => function( $v ){ return (int) (bool) $v; }
        ] );

        add_settings_section( 'fr_main', __( 'Settings', 'facilitated-routines' ), '__return_false', 'facilitated-routines' );
        add_settings_field(
            self::OPTION_KEY_RENAME_ON_UPLOAD,
            __( 'Rename images on upload', 'facilitated-routines' ),
            [ $this, 'render_toggle_field' ],
            'facilitated-routines', 'fr_main'
        );
        add_settings_field(
            self::OPTION_KEY_AUTO_UPDATE,
            __( 'Enable auto updates for this plugin', 'facilitated-routines' ),
            [ $this, 'render_auto_update_field' ],
            'facilitated-routines', 'fr_main'
        );
    }

    public function render_toggle_field() {
        $enabled = (int) get_option( self::OPTION_KEY_RENAME_ON_UPLOAD, 1 );
        echo '<label><input type="checkbox" name="'.esc_attr(self::OPTION_KEY_RENAME_ON_UPLOAD).'" value="1" '.checked(1, $enabled, false).' /> ';
        echo esc_html__( 'When checked, uploaded images will be renamed when the post is published or updated.', 'facilitated-routines' ) . '</label>';
    }
    public function render_auto_update_field() {
        $enabled = (int) get_option( self::OPTION_KEY_AUTO_UPDATE, 1 );
        echo '<label><input type="checkbox" name="'.esc_attr(self::OPTION_KEY_AUTO_UPDATE).'" value="1" '.checked(1, $enabled, false).' /> ';
        echo esc_html__( 'Allow WordPress to auto install new releases from GitHub.', 'facilitated-routines' ) . '</label>';
    }

    public function enqueue_assets( $hook ) {
        if ( 'settings_page_facilitated-routines' !== $hook ) { return; }
        wp_register_script( 'facilitated-routines-admin', false, [ 'jquery' ], FACILITATED_ROUTINES_VERSION, true );
        wp_enqueue_script( 'facilitated-routines-admin' );
        wp_register_style( 'facilitated-routines-admin', false, [], FACILITATED_ROUTINES_VERSION );
        wp_add_inline_style( 'facilitated-routines-admin', $this->admin_css() );
        wp_enqueue_style( 'facilitated-routines-admin' );

        wp_localize_script( 'facilitated-routines-admin', 'FRI18N', [
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'noncePrepare' => wp_create_nonce( 'facilitated_routines_prepare' ),
            'nonceProcess' => wp_create_nonce( 'facilitated_routines_process' ),
            'preparing'    => __( 'Preparing...', 'facilitated-routines' ),
            'completed'    => __( 'completed', 'facilitated-routines' ),
            'failPrepare'  => __( 'Failed to prepare processing.', 'facilitated-routines' ),
            'failBatch'    => __( 'Failed to process batch.', 'facilitated-routines' ),
            'failAjax'     => __( 'AJAX request error.', 'facilitated-routines' ),
            'nothing'      => __( 'Nothing to process.', 'facilitated-routines' ),
            'labels'       => [
                'processed' => __( 'Processed', 'facilitated-routines' ),
                'of'        => __( 'of', 'facilitated-routines' ),
                'renamed'   => __( 'Renamed', 'facilitated-routines' ),
                'skipped'   => __( 'Skipped', 'facilitated-routines' ),
                'errors'    => __( 'Errors', 'facilitated-routines' ),
            ],
        ] );
        wp_add_inline_script( 'facilitated-routines-admin', $this->admin_js() );
    }

    private function admin_css() {
        return ' 
        #fr-progress{display:none;width:100%;background:#f0f0f1;height:18px;border-radius:4px;overflow:hidden;margin-top:8px;border:1px solid #dcdcde}
        #fr-bar{height:100%;width:0%}
        #fr-stats{margin-top:6px;font-size:12px;color:#2c3338}
        #fr-actions{margin:12px 0}
        ';
    }

    private function admin_js() {
        return "jQuery(function($){
            let total = 0, processed = 0, page = 1, batch = 25, renamed=0, skipped=0, errors=0, running=false;
            function statsLine(){
                return FRI18N.labels.processed + ': ' + processed + ' ' + FRI18N.labels.of + ' ' + total +
                    ' | ' + FRI18N.labels.renamed + ': ' + renamed +
                    ' | ' + FRI18N.labels.skipped + ': ' + skipped +
                    ' | ' + FRI18N.labels.errors + ': ' + errors;
            }
            function updateBar(){
                const pct = total > 0 ? Math.min(100, Math.round((processed/total)*100)) : 0;
                $('#fr-bar').css({ width: pct + '%', background: pct===100 ? '#46b450' : '#2271b1' });
                $('#fr-stats').text(statsLine());
            }
            function processPage(){
                $.post(FRI18N.ajaxUrl, { action: 'facilitated_routines_process_bulk', _ajax_nonce: FRI18N.nonceProcess, page: page, batch: batch })
                .done(function(resp){
                    if(!resp || !resp.success){ finish(FRI18N.failBatch); return; }
                    const d = resp.data || {}; processed += d.processed || 0; renamed += d.renamed || 0; skipped += d.skipped || 0; errors += d.errors || 0;
                    updateBar(); if(d.done || processed >= total){ finish(); return; } page++; setTimeout(processPage, 120);
                }).fail(function(){ finish(FRI18N.failAjax); });
            }
            function finish(msg){
                running = false; $('#fr-run').prop('disabled', false); if(msg){ alert(msg); }
                if(processed >= total){ $('#fr-stats').append(' ✔️ ' + FRI18N.completed); }
            }
            $('#fr-run').on('click', function(e){
                e.preventDefault(); if(running){ return; } running = true; $(this).prop('disabled', true);
                $('#fr-progress').show(); $('#fr-bar').css({width:'0%'}); $('#fr-stats').text(FRI18N.preparing);
                $.post(FRI18N.ajaxUrl, { action: 'facilitated_routines_prepare_bulk', _ajax_nonce: FRI18N.noncePrepare })
                .done(function(resp){
                    if(!resp || !resp.success){ finish(FRI18N.failPrepare); return; }
                    const d = resp.data || {}; total = d.total || 0; batch = d.batch || batch; processed = 0; page = 1; renamed=0; skipped=0; errors=0;
                    updateBar(); if(total === 0){ finish(FRI18N.nothing); return; } processPage();
                }).fail(function(){ finish(FRI18N.failAjax); });
            });
        });";
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; } ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Facilitated Routines', 'facilitated-routines' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( 'facilitated-routines' );
                submit_button( esc_html__( 'Save changes', 'facilitated-routines' ) );
                ?>
            </form>
            <hr />
            <h2><?php echo esc_html__( 'Bulk rename', 'facilitated-routines' ); ?></h2>
            <p><?php echo esc_html__( 'Renames the featured image files of all posts to the slug of each post title, with progress.', 'facilitated-routines' ); ?></p>
            <div id="fr-actions">
                <button id="fr-run" class="button button-secondary"><?php echo esc_html__( 'Bulk rename', 'facilitated-routines' ); ?></button>
                <div id="fr-progress"><div id="fr-bar"></div></div>
                <div id="fr-stats"></div>
            </div>
        </div>
        <?php
    }

    /* =================== AJAX =================== */
    public function ajax_prepare_bulk() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
        check_ajax_referer( 'facilitated_routines_prepare' );
        global $wpdb;
        $allowed_status = array('publish','future','draft','pending','private');
        $placeholders = implode(',', array_fill(0, count($allowed_status), '%s'));
        $sql = "
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
            WHERE p.post_status IN ({$placeholders})
        ";
        $params = array_merge( array('_thumbnail_id'), $allowed_status );
        $total = (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
        $batch = max( 5, min( 50, apply_filters( 'facilitated_routines_bulk_batch', 25 ) ) );
        wp_send_json_success( array( 'total' => $total, 'batch' => $batch ) );
    }

    public function ajax_process_bulk() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
        check_ajax_referer( 'facilitated_routines_process' );
        $page  = max( 1, absint( $_POST['page'] ?? 1 ) );
        $batch = max( 1, min( 100, absint( $_POST['batch'] ?? 25 ) ) );
        $q = new WP_Query( array(
            'post_type'              => 'any',
            'post_status'            => array('publish','future','draft','pending','private'),
            'meta_key'               => '_thumbnail_id',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'posts_per_page'         => $batch,
            'paged'                  => $page,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ) );
        $renamed = 0; $skipped = 0; $errors = 0; $processed = 0;
        if ( $q->have_posts() ) {
            foreach ( $q->posts as $post_id ) {
                $processed++;
                $thumb_id = get_post_thumbnail_id( $post_id );
                if ( ! $thumb_id ) { $skipped++; continue; }
                $result = $this->rename_attachment_to_post_title( $thumb_id, $post_id );
                if ( true === $result ) { $renamed++; }
                elseif ( 'skip' === $result ) { $skipped++; }
                else { $errors++; }
            }
        }
        $done = ( $processed < $batch || 0 === $processed );
        wp_send_json_success( array(
            'processed' => $processed,
            'renamed'   => $renamed,
            'skipped'   => $skipped,
            'errors'    => $errors,
            'done'      => $done,
        ) );
    }

    /* =================== Núcleo de renomeação =================== */
    private function rename_attachment_to_post_title( $attachment_id, $parent_post_id ) {
        $file_path = get_attached_file( $attachment_id );
        if ( ! $file_path || ! file_exists( $file_path ) ) { return 'skip'; }
        $mime = get_post_mime_type( $attachment_id );
        if ( ! is_string( $mime ) || false === strpos( $mime, 'image/' ) ) { return 'skip'; }
        $post = get_post( $parent_post_id );
        if ( ! $post || '' === trim( $post->post_title ) || 'auto-draft' === $post->post_status ) { return 'skip'; }
        $slug = sanitize_title( $post->post_title );
        if ( '' === $slug ) { return 'skip'; }
        $pathinfo = pathinfo( $file_path );
        $ext      = isset( $pathinfo['extension'] ) ? strtolower( $pathinfo['extension'] ) : '';
        if ( '' === $ext ) { return 'skip'; }
        $dir  = $pathinfo['dirname'];
        $dest = wp_unique_filename( $dir, $slug . '.' . $ext );
        if ( $dest === $pathinfo['basename'] ) { return 'skip'; }
        $new_path = trailingslashit( $dir ) . $dest;
        if ( ! @rename( $file_path, $new_path ) ) { return 'error moving file'; }
        $uploads = wp_get_upload_dir();
        if ( false === strpos( $new_path, $uploads['basedir'] ) ) { return 'path outside uploads'; }
        $relative = ltrim( str_replace( $uploads['basedir'], '', $new_path ), '/' );
        update_attached_file( $attachment_id, $new_path );
        update_post_meta( $attachment_id, '_wp_attached_file', $relative );
        $meta = wp_generate_attachment_metadata( $attachment_id, $new_path );
        if ( ! is_wp_error( $meta ) && ! empty( $meta ) ) { wp_update_attachment_metadata( $attachment_id, $meta ); }
        $new_url = trailingslashit( $uploads['baseurl'] ) . $relative;
        wp_update_post( array( 'ID' => $attachment_id, 'guid' => esc_url_raw( $new_url ), 'post_name' => sanitize_title( $dest ) ) );
        return true;
    }
}

new Facilitated_Routines();
