<?php
/*
    Plugin Name: miladoll-remove-uri-protohost
    Plugin URI: https://tech.miladoll.jp/
    Description: `miladoll-remove-uri-protohost` removes protocols and host part of URI to avoid mixed contents
    Version: 0.1.2
    Author: MILADOLL Decchi
    Author URI: https://tech.miladoll.jp/
    License: MIT
*/

class miladoll_remove_uri_protohost {
    public function miladoll_remove_uri_protohost() {
        add_action( 'wp_head', array(&$this, 'wp_head') , 1 );
        add_action( 'wp_footer', array(&$this, 'wp_footer') , 99999 );
    }
    public function wp_head(){
        ob_start( array(&$this, 'replace_relative_URI') );
    }
    public function wp_footer(){
        ob_end_flush();
    }
    public function replace_relative_URI( $content ) {
        $HOME_URL = home_url();
        $FQDN = array_shift( explode( '/', (explode( '//', $HOME_URL ))[1] ) );
        $PREG_QUOTED_URL = preg_quote( $HOME_URL, '/' );
        $PREG_QUOTED_URL = str_replace( 'http\:\/\/', '(?:http\:\/\/|\/\/)', $PREG_QUOTED_URL );
        $PREG_QUOTED_FQDN = preg_quote( $FQDN );

        // 自ホスト絶対URL絶対殺すマン
        // $content = str_replace(
        //     trailingslashit( $HOME_URL ),
        //     '/',
        //     $content
        // );

        // 置換対象の属性が同時に1回しか現れない要素群
        $content =
            preg_replace_callback(
                "/(<(?:a|script|link|form|meta)[^<>]{1,}(?:src|href|action|content)=[\"'])("
                    . $PREG_QUOTED_URL
                    . "{0,1})/is",
                function( $matches ) {
                    $m = $matches[1];
                    $original_url = $matches[2];
                    /*
                        本当なら og:url も対象にしていい気がするが、
                        とりあえず様子見
                    */
                    if ( preg_match( '/(canonical)/i', $m ) ) {
                        $m = "$m$original_url";
                    }
                    return( "$m" );
                },
                $content
            )
        ;
        // 置換対象の属性が同時に1回以上現れる要素群
        foreach ( array( 'src', 'data-lazy-src', 'data-full-url', 'data-link' ) as $attr ) {
            $content =
                preg_replace(
                    '/(<img[^<>]*?\s' . $attr . '=["\'])'
                        . $PREG_QUOTED_URL
                        . '/is',
                    "$1",
                    $content
                )
            ;
        }
        // img srcset系
        $content =
            preg_replace_callback(
                "/(<img.*?(?:lazy-srcset|srcset)=[\"'])([^\"']+)([\"'])/is",
                function( $matches ) use ( $PREG_QUOTED_URL ) {
                    $srcset = '';
                    $srcset = preg_replace(
                        '/' . $PREG_QUOTED_URL . '/i',
                        '',
                        $matches[2]
                    );
                    return( "$matches[1]$srcset$matches[3]" );
                },
                $content
            )
        ;
        // script内の文字列
        $content =
            preg_replace_callback(
                '/(<script>)(.*?)(<\/script>)/is',
                function ( $matches ) use ( $PREG_QUOTED_URL, $PREG_QUOTED_FQDN ) {
                    $DOUBLE_QUOTED_URL = preg_quote( $PREG_QUOTED_URL, '/' );
                    $PROTO = '(?:http|https):';
                    $DOUBLE_QUOTED_SEP = preg_quote( preg_quote( '//', '/' ), '/' );
                    $COMPLEX_URL = "$PROTO$DOUBLE_QUOTED_SEP$PREG_QUOTED_FQDN";
                    $t = $matches[2];
                    // WP emoji
                    $t =
                        preg_replace(
                            "/(\"concatemoji\":\")$COMPLEX_URL/",
                            "$1",
                            $t
                        );
                    // JetPack
                    $t =
                        preg_replace(
                            "/(\"scan_endpoint\":\")$COMPLEX_URL/",
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
}

new miladoll_remove_uri_protohost();
