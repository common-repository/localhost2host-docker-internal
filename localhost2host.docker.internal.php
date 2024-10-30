<?php 
/*
  Plugin Name: localhost2host.docker.internal
  Description: For Docker wp development: Replace loopback requests to localhost with host.docker.internal
  Version: 0.4.0
  Author: enomoto@celtislab
  Author URI: https://celtislab.net/
  Requires at least: 6.3
  Tested up to: 6.5
  Requires PHP: 8.1
  License: GPLv2
 */

if ( !defined( 'ABSPATH' ) ) exit;

/*
 * Server side request filter
 * 
 * @param false|array|WP_Error $preempt     A preemptive return value of an HTTP request. Default false.
 * @param array                $parsed_args HTTP request arguments.
 * @param string               $url         The request URL.
 */
function lh2hdi_pre_http_request($preempt, $parsed_args, $url) {
    if(isset($url) && strpos($url, '//localhost') !== false && file_exists('/.dockerenv')){
		$ip = gethostbyname( 'host.docker.internal' );
        //Returns the IPv4 address or a string containing the unmodified hostname on failure.
		if ($ip !== 'host.docker.internal') {
            //Replace localhost in URL with host.docker.internal
            //and Replace https with http and turn off sslverify
            $url = str_replace('localhost', 'host.docker.internal', $url);
            $url = str_replace('https', 'http', $url);
            if ( $parsed_args['sslverify'] ) {
                unset($parsed_args['sslverify']);
            }
                        
            //HTTP API: WP_Http class class-wp-http.php request() function 
            if ( function_exists( 'wp_kses_bad_protocol' ) ) {
                if ( $parsed_args['reject_unsafe_urls'] ) {
                    $url = wp_http_validate_url( $url );
                }
                if ( $url ) {
                    $url = wp_kses_bad_protocol( $url, array( 'http', 'https', 'ssl' ) );
                }
            }

            $parsed_url = wp_parse_url( $url );
            if ( empty( $url ) || empty( $parsed_url['scheme'] ) ) {
                $response = new WP_Error( 'http_request_failed', 'A valid URL was not provided.' );
                do_action( 'http_api_debug', $response, 'response', 'WpOrg\Requests\Requests', $parsed_args, $url );
                return $response;
            }

            // If we are streaming to a file but no filename was given drop it in the WP temp dir
            // and pick its name using the basename of the $url.
            if ( $parsed_args['stream'] ) {
                if ( empty( $parsed_args['filename'] ) ) {
                    $parsed_args['filename'] = get_temp_dir() . basename( $url );
                }
                // Force some settings if we are streaming to a file and check for existence
                // and perms of destination directory.
                $parsed_args['blocking'] = true;
                if ( ! wp_is_writable( dirname( $parsed_args['filename'] ) ) ) {
                    $response = new WP_Error( 'http_request_failed', 'Destination directory for file streaming does not exist or is not writable.' );
                    do_action( 'http_api_debug', $response, 'response', 'WpOrg\Requests\Requests', $parsed_args, $url );
                    return $response;
                }
            }

            if ( is_null( $parsed_args['headers'] ) ) {
                $parsed_args['headers'] = array();
            }

            // WP allows passing in headers as a string, weirdly.
            if ( ! is_array( $parsed_args['headers'] ) ) {
                $processed_headers      = WP_Http::processHeaders( $parsed_args['headers'] );
                $parsed_args['headers'] = $processed_headers['headers'];
            }

            // Setup arguments.
            $headers = $parsed_args['headers'];
            $data    = $parsed_args['body'];
            $type    = $parsed_args['method'];
            $options = array(
                'timeout'   => $parsed_args['timeout'],
                'useragent' => $parsed_args['user-agent'],
                'blocking'  => $parsed_args['blocking'],
                // do not use hook
                //'hooks'     => new WP_HTTP_Requests_Hooks( $url, $parsed_args ),
            );

            if ( $parsed_args['stream'] ) {
                $options['filename'] = $parsed_args['filename'];
            }
            if ( empty( $parsed_args['redirection'] ) ) {
                $options['follow_redirects'] = false;
            } else {
                $options['redirects'] = $parsed_args['redirection'];
            }

            // Use byte limit, if we can.
            if ( isset( $parsed_args['limit_response_size'] ) ) {
                $options['max_bytes'] = $parsed_args['limit_response_size'];
            }

            // If we've got cookies, use and convert them to WpOrg\Requests\Cookie.
            if ( ! empty( $parsed_args['cookies'] ) ) {
                $options['cookies'] = WP_Http::normalize_cookies( $parsed_args['cookies'] );
            }

            // SSL certificate handling.
            if ( ! $parsed_args['sslverify'] ) {
                $options['verify']     = false;
                $options['verifyname'] = false;
            } else {
                $options['verify'] = $parsed_args['sslcertificates'];
            }            

            // All non-GET/HEAD requests should put the arguments in the form body.
            if ( 'HEAD' !== $type && 'GET' !== $type ) {
                $options['data_format'] = 'body';
            }

            // Check for proxies.
            $proxy = new WP_HTTP_Proxy();
            if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
                $options['proxy'] = new WpOrg\Requests\Proxy\Http( $proxy->host() . ':' . $proxy->port() );

                if ( $proxy->use_authentication() ) {
                    $options['proxy']->use_authentication = true;
                    $options['proxy']->user               = $proxy->username();
                    $options['proxy']->pass               = $proxy->password();
                }
            }

            // Avoid issues where mbstring.func_overload is enabled.
            mbstring_binary_safe_encoding();

            try {
                $requests_response = WpOrg\Requests\Requests::request( $url, $headers, $data, $type, $options );

                // Convert the response into an array.
                $http_response = new WP_HTTP_Requests_Response( $requests_response, $parsed_args['filename'] );
                $response      = $http_response->to_array();

                // Add the original object to the array.
                $response['http_response'] = $http_response;
            } catch ( WpOrg\Requests\Exception $e ) {
                $response = new WP_Error( 'http_request_failed', $e->getMessage() );
            }

            reset_mbstring_encoding();

            /**
             * Fires after an HTTP API response is received and before the response is returned.
             *
             * @since 2.8.0
             *
             * @param array|WP_Error $response    HTTP response or WP_Error object.
             * @param string         $context     Context under which the hook is fired.
             * @param string         $class       HTTP transport used.
             * @param array          $parsed_args HTTP request arguments.
             * @param string         $url         The request URL.
             */
            do_action( 'http_api_debug', $response, 'response', 'WpOrg\Requests\Requests', $parsed_args, $url );
            if ( is_wp_error( $response ) ) {
                return $response;
            }

            if ( ! $parsed_args['blocking'] ) {
                return array(
                    'headers'       => array(),
                    'body'          => '',
                    'response'      => array(
                        'code'    => false,
                        'message' => false,
                    ),
                    'cookies'       => array(),
                    'http_response' => null,
                );
            }

            /**
             * Filters a successful HTTP API response immediately before the response is returned.
             *
             * @since 2.9.0
             *
             * @param array  $response    HTTP response.
             * @param array  $parsed_args HTTP request arguments.
             * @param string $url         The request URL.
             */
            return apply_filters( 'http_response', $response, $parsed_args, $url );			
		}
    } 
    return $preempt;
}    
add_filter( 'pre_http_request', 'lh2hdi_pre_http_request', 10, 3);
