<?php
defined( 'ABSPATH' ) || exit;

add_filter( 'graphql_response_headers_to_send', 'soames_graphql_cors_headers' );

function soames_graphql_cors_headers( $headers ) {
    $frontend_url = get_option( 'soames_frontend_url' );
    if ( ! $frontend_url ) return $headers;

    $origin          = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '';
    $frontend_origin = rtrim( $frontend_url, '/' );

    if ( $origin === $frontend_origin ) {
        $headers['Access-Control-Allow-Origin']      = $frontend_origin;
        $headers['Access-Control-Allow-Credentials'] = 'true';
    }

    return $headers;
}

add_action( 'init', 'soames_graphql_cors_preflight' );

function soames_graphql_cors_preflight() {
    if ( $_SERVER['REQUEST_METHOD'] !== 'OPTIONS' ) return;

    $frontend_url = get_option( 'soames_frontend_url' );
    if ( ! $frontend_url ) return;

    $origin          = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '';
    $frontend_origin = rtrim( $frontend_url, '/' );

    if ( $origin !== $frontend_origin ) return;

    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
    if ( strpos( $request_uri, 'graphql' ) === false ) return;

    header( 'Access-Control-Allow-Origin: ' . $frontend_origin );
    header( 'Access-Control-Allow-Credentials: true' );
    header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS' );
    header( 'Access-Control-Allow-Headers: Content-Type' );
    status_header( 204 );
    exit;
}
