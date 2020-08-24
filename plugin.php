<?php
/*
    Plugin Name: miladoll-remove-uri-protohost
    Plugin URI: https://miladoll.jp/
    Description: `miladoll-remove-uri-protohost` removes protocols and host part of URI to avoid mixed contents
    Version: 0.5.2
    Author: MILADOLL Decchi
    Author URI: https://miladoll.jp/
    License: MIT
*/

class miladoll_remove_uri_protohost {
    private $fqdn = '';
    private $admin_page_to_add = 'general';

    public function miladoll_remove_uri_protohost() {
        self::setup_plugin();
        $this->fqdn = self::get_target_fqdn();
        if ( ! is_admin() ) {
            // 管理ページ下で動作させると異常を呼ぶので除外
            // 　例）「一般設定」→「サイトアドレス」値が消えるなど
            self::add_action_when_loaded();
        }
        self::add_filter_when_getting_attachment_url();
    }

    public function setup_plugin() {
        // Plugin API
        require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
        $this->plugin = get_plugin_data( __FILE__ );
        // 1st time
        $opt_fqdn = self::get_plugins_option( 'fqdn' );
        if ( ! $opt_fqdn ) {
            // デフォルト
            $home_url = home_url();
            $this_fqdn = array_shift( explode( '/', (explode( '//', $home_url ))[1] ) );
            self::set_plugins_option( 'fqdn', $this_fqdn );
        }
        if ( is_admin() ) {
            // プラグイン [設定] 追加
            add_filter(
                'plugin_action_links',
                array( $this, 'admin_set_link_for_plugin_admin' ),
                10, 2
            );
            add_filter(
                'admin_init',
                array( $this, 'admin_add_setting_field' ),
                10, 1
            );
        }
    }

    /*
        ヘルパメソッド
    */
    // 次の文字だけ regex エスケープ： .
    public function partial_preg_quote( $str ) {
        $str = str_replace( '.', '\.', $str );
        return $str;
    }
    // クラス名取得
    public function get_class_name() {
        return( get_class( $this ) );
    }
    // 関連オプション名はすべて {$class_name}__{$opt} で統一する
    public function get_plugin_option_name( $opt ) {
        $class_name = self::get_class_name();
        return( "{$class_name}__$opt" );
    }
    // オプションgetter
    public function get_plugins_option( $opt ) {
        return( get_option( self::get_plugin_option_name( $opt ) ) );
    }
    // オプションsetter
    public function set_plugins_option( $opt, $value ) {
        update_option( self::get_plugin_option_name( $opt ), $value );
    }
    public function get_target_fqdn() {
        $this_fqdn = self::get_plugins_option( 'fqdn' );
        return $this_fqdn;
    }
    /*
        使用しているオプション：
        * miladoll_remove_uri_protohost__fqdn
            * 正規化（削除）の対象となるFQDN。| 区切りで列記
            * `.` はエスケープされずに収容する
            * グルーピング `(?:...)` は取得した者がやる
            * まとめて preg_quote すると | (?:) がエスケープされるので注意
    */

    /*
        管理画面追加
    */
    // プラグイン一覧ページの自分行に [設定] 追加
    public function admin_set_link_for_plugin_admin( $links, $file ) {
        $option_name_fqdn = self::get_plugin_option_name( 'fqdn' );
        if (
            $file == plugin_basename( __FILE__ )
            && current_user_can( 'manage_options' )
        ) {
            array_unshift(
                $links,
                '<a href="options-general.php#' . $option_name_fqdn . '">'
                . __( 'Settings' )
                . '</a>'
            );
        }
        return $links;
    }
    // オプション general セクションにオプション領域を追加する
    public function admin_add_setting_field() {
        $page_to_add = $this->admin_page_to_add;
        $option_name_fqdn = self::get_plugin_option_name( 'fqdn' );
        add_settings_field(
            $option_name_fqdn,
            __( '相対化対象FQDNs' ),
            array( $this, 'admin_draw_option' ),
            $page_to_add,
        );
        register_setting(
            $page_to_add,
            $option_name_fqdn
        );
    }
    public function admin_draw_option() {
        $opt_fqdn = self::get_plugins_option( 'fqdn' );
        $option_name_fqdn = self::get_plugin_option_name( 'fqdn' );
        ?>
            <fieldset>
                <input
                    type="text"
                    name="<?= $option_name_fqdn ?>"
                    id="<?= $option_name_fqdn ?>"
                    value="<?= $opt_fqdn ?>"
                    style="width: 32em;"
                >
                <p>
                    指定したドメインのURLがHTMLタグ中に存在した場合
                        <code>https?://FQDN</code>
                    部分を削除します。<br>
                    複数ドメインの指定は
                        <code>|</code>
                    で区切ります
                </p>
            </fieldset>
            <script type="text/javascript">
                // 無理やり [サイトアドレス (URL)] の次に移動させる
                jQuery( '#<?= $option_name_fqdn ?>' )
                    .closest( 'tr' )
                    .insertAfter(
                        jQuery( '#home' ).closest( 'tr' )
                    )
                ;
            </script>
        <?php
    }

    /*
        メインブロック
    */

    /*
        レンダされたHTMLを総なめにするURLの正規化
    */
    /*
        なぜか
            add_action( 'wp_head', array( $this, 'wp_head') , 1 );
            add_action( 'wp_footer', array( $this, 'wp_footer') , PHP_INT_MAX );
                :
            public function wp_head(){
                ob_start( array( $this, 'replace_relative_URI') );
                :
            public function wp_footer(){
                ob_end_flush();
                :
        のようにしなくてもいいということなので変更
    */
    public function add_action_when_loaded() {
        add_action( 'wp_loaded', array( $this, 'ob' ) , PHP_INT_MAX, 1 );
    }
    public function ob() {
        ob_start( array( $this, 'from_remove_http' ) );
    }
    //  URL正規化本体
    public function from_remove_http( $content ) {
        $this_fqdn =  $this->fqdn;
        // `.` だけエスケープする
        $preg_quoted_fqdn = self::partial_preg_quote( $this_fqdn );
        $preg_quoted_fqdn = "(?:$preg_quoted_fqdn)";
        $regex =
            '(?:https?:|)\/\/'
            . $preg_quoted_fqdn
        ;
        // <>タグ内を根こそぎ正規化
        //  TODO: やりすぎて問題が発生した場合は属性単位で正規化する
        $content =
            preg_replace_callback(
                "/(<)([^<>]{1,})(>)/is",
                function( $matches ) use ( $regex ) {
                    // `//fqdn.jp` のような表記も統一する
                    $m = $matches[2];
                    /*
                        以下を対象から外す
                            <link rel="canonical" href="https://..." />
                            <link rel='shortlink' href='https://...' />
                            <meta property="og:url" content="https://..." />
                    */
                    if ( 
                        ( preg_match( '/link\s+rel=[\"\'](?:canonical|shortlink)[\"\']/is', $m ) )
                        || ( preg_match( '/meta\s+property=[\"\']og:url[\"\']/is', $m ) )
                    ) {
                        // そのまま返却
                        return(
                            $matches[1]
                            . "$m"
                            . $matches[3]
                        );
                    }
                    $m = preg_replace(
                        '/'
                            . $regex
                        . '/is',
                        '',
                        $m
                    );
                    return(
                        $matches[1]
                        . "$m"
                        . $matches[3]
                    );
                },
                $content
            )
        ;
        // <style>内のURLを正規化
        $proto = '(?:http|https):';
        $single_quoted_sep = preg_quote( '//', '/' );
        $simple_url = "$proto$single_quoted_sep$preg_quoted_fqdn";
        $content =
            preg_replace_callback(
                '/(<style[^>]{0,}>)(.*?)(<\/style>)/is',
                function ( $matches ) use ( $simple_url ) {
                    $m = $matches[2];
                    $m =
                        preg_replace(
                            "/$simple_url/is",
                            '',
                            $m
                        );
                    return( "$matches[1]$m$matches[3]" );
                },
                $content
            )
        ;
        // <script>内の特定のURLを正規化
        $double_quoted_sep = preg_quote( preg_quote( '//', '/' ), '/' );
        $complex_url = "$proto$double_quoted_sep$preg_quoted_fqdn";
        $content =
            preg_replace_callback(
                '/(<script>)(.*?)(<\/script>)/is',
                function ( $matches ) use ( $complex_url ) {
                    $t = $matches[2];
                    // WP emoji
                    $t =
                        preg_replace(
                            "/(\"concatemoji\":\")$complex_url/",
                            "$1",
                            $t
                        );
                    // JetPack
                    $t =
                        preg_replace(
                            "/(\"scan_endpoint\":\")$complex_url/",
                            "$1",
                            $t
                        );
                    return( "$matches[1]$t$matches[3]" );
                },
                $content
            )
        ;
        return $content;
    }

    /*
        動的に取得されるURLの正規化
    */
    // wp_get_attachment_urlなどが動的にURLを取得するためのフック
    public function add_filter_when_getting_attachment_url() {
        add_filter(
            'wp_get_attachment_url',
            array( $this, 'get_attachments_url_path_part_only' )
        );
        add_filter(
            'attachment_link',
            array( $this, 'get_attachments_url_path_part_only' )
        );
    }
    //  動的に取得されるURLの正規化本体
    public function get_attachments_url_path_part_only( $url ) {
        $this_fqdn =  $this->fqdn;
        // `.` だけエスケープする
        $preg_quoted_fqdn = self::partial_preg_quote( $this_fqdn );
        $preg_quoted_fqdn = "(?:$preg_quoted_fqdn)";
        $regex =
            '/'
                . '^https?:\/\/'
                . $preg_quoted_fqdn
                . '(.*)$'
            . '/';
        if ( preg_match( $regex, $url, $matches ) ) {
            $url = $matches[1];
        }
        return( $url );
    }
}

new miladoll_remove_uri_protohost();
